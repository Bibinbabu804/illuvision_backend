<?php

namespace App\Services;

use App\Models\Screen;
use App\Models\Country;
use App\Models\State;
use App\Models\City;
use App\Models\ScreenCategory;
use App\Models\ScreenProvider;
use App\Models\PricingPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class AssetImportService
{
    public function __construct(
        protected AssetService $assetService
    ) {}

    /**
     * Alias Dictionary for header-based mapping.
     */
    protected array $headerAliases = [
        'name' => ['asset name', 'name', 'asset', 'screen name', 'asset_name', 'screen_name', 'title'],
        'screen_category' => ['screen category', 'category', 'asset category', 'screen_category', 'category_name'],
        'country' => ['country', 'country name', 'country_name', 'nation'],
        'state' => ['state', 'state name', 'province', 'state_name', 'region'],
        'city' => ['city', 'city name', 'city_name', 'district'],
        'location_name' => ['location name', 'location', 'address', 'location_name', 'venue'],
        'latitude' => ['latitude', 'lat', 'latitude_deg'],
        'longitude' => ['longitude', 'lng', 'long', 'lon', 'longitude_deg'],
        'screen_code' => ['screen code', 'code', 'asset code', 'screen_code', 'asset_id_code'],
        'illuvision_percentage' => ['illuvision share (%)', 'illuvision share', 'illuvision %', 'illuvision percentage', 'illuvision_share', 'illuvision_pct'],
        'screen_provider_percentage' => ['provider share (%)', 'provider share', 'provider %', 'provider percentage', 'screen provider share', 'screen_provider_share', 'provider_pct'],
        'status' => ['status', 'operating status', 'state_status'],
        'orientation' => ['orientation', 'display orientation', 'screen orientation'],
        'resolution' => ['resolution', 'screen resolution', 'display resolution'],
        'operating_hours' => ['operating hours', 'hours', 'operating_hours', 'timings'],
    ];

    protected array $requiredFields = [
        'name',
        'screen_category',
        'country',
        'state',
        'city',
        'location_name',
    ];

    /**
     * Normalize header string for flexible matching.
     */
    public function normalizeHeader(string $header): string
    {
        $cleaned = trim($header);
        $cleaned = Str::lower($cleaned);
        $cleaned = preg_replace('/[_\-\/\\\\%()]+/', ' ', $cleaned);
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        return trim($cleaned);
    }

    /**
     * Build map of raw Excel column index => canonical field name.
     */
    public function buildHeaderMap(array $rawHeaders): array
    {
        $headerMap = [];
        $unmapped = [];

        foreach ($rawHeaders as $index => $rawHeader) {
            if (empty($rawHeader)) continue;
            $normalized = $this->normalizeHeader((string) $rawHeader);
            $mappedField = null;

            foreach ($this->headerAliases as $canonicalField => $aliases) {
                foreach ($aliases as $alias) {
                    if ($this->normalizeHeader($alias) === $normalized) {
                        $mappedField = $canonicalField;
                        break 2;
                    }
                }
            }

            if ($mappedField) {
                $headerMap[$index] = $mappedField;
            } else {
                $unmapped[$index] = $rawHeader;
            }
        }

        return [$headerMap, $unmapped];
    }

    /**
     * Validate Excel file and generate preview.
     */
    public function validateImport(
        string $filePath,
        int $providerId,
        int $pricingPlanId,
        bool $autoCreateLocations = true,
        bool $restrictDuplicates = true
    ): array {
        $provider = ScreenProvider::find($providerId);
        if (!$provider) {
            throw new \InvalidArgumentException("Selected Screen Provider #{$providerId} not found.");
        }

        $pricingPlan = PricingPlan::find($pricingPlanId);
        if (!$pricingPlan) {
            throw new \InvalidArgumentException("Selected Pricing Plan #{$pricingPlanId} not found.");
        }

        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rowsArray = $worksheet->toArray(null, true, true, true);

        if (empty($rowsArray)) {
            return [
                'success' => false,
                'message' => 'The uploaded file is empty.',
            ];
        }

        // Extract header row (row 1)
        $firstRowKey = array_key_first($rowsArray);
        $rawHeaders = $rowsArray[$firstRowKey];
        unset($rowsArray[$firstRowKey]);

        list($headerMap, $unmappedHeaders) = $this->buildHeaderMap($rawHeaders);

        // Verify required headers are present
        $mappedCanonicalFields = array_values($headerMap);
        $missingRequired = [];
        foreach ($this->requiredFields as $reqField) {
            if (!in_array($reqField, $mappedCanonicalFields)) {
                $readableName = Str::headline($reqField);
                $missingRequired[] = "'{$readableName}'";
            }
        }

        if (!empty($missingRequired)) {
            return [
                'success' => false,
                'message' => 'Missing required column headers in Excel file: ' . implode(', ', $missingRequired),
                'unmapped_headers' => array_values($unmappedHeaders),
            ];
        }

        // Cache DB master tables to minimize queries
        $countries = Country::all()->keyBy(fn($c) => Str::lower($c->name));
        $states = State::all();
        $cities = City::all();
        $categories = ScreenCategory::all();

        // Existing codes & duplicate tracker
        $existingCodes = Screen::pluck('screen_code')->filter()->map(fn($c) => Str::upper($c))->flip()->toArray();

        $existingUniqueKeys = [];
        Screen::with('city')->get()->each(function ($s) use (&$existingUniqueKeys) {
            if ($s->city_id) {
                $k1 = Str::lower(trim($s->name)) . '|id:' . $s->city_id . '|' . Str::lower(trim($s->location_name));
                $existingUniqueKeys[$k1] = true;
            }
            if ($s->city) {
                $k2 = Str::lower(trim($s->name)) . '|name:' . Str::lower(trim($s->city->name)) . '|' . Str::lower(trim($s->location_name));
                $existingUniqueKeys[$k2] = true;
            }
        });

        $fileSeenCodes = [];
        $fileSeenUniqueKeys = [];

        $parsedRows = [];
        $totalRows = 0;
        $validRowsCount = 0;
        $invalidRowsCount = 0;
        $duplicateRowsCount = 0;

        $rowNumber = 1;
        foreach ($rowsArray as $excelRow) {
            $rowNumber++;
            // Check if entire row is empty
            if (!array_filter($excelRow, fn($v) => !is_null($v) && trim((string)$v) !== '')) {
                continue;
            }

            $totalRows++;
            $rowData = [];
            foreach ($headerMap as $colLetter => $canonicalKey) {
                $val = $excelRow[$colLetter] ?? null;
                $rowData[$canonicalKey] = is_string($val) ? trim($val) : $val;
            }

            $errors = [];
            $warnings = [];

            // 1. Asset Name
            $assetName = $rowData['name'] ?? '';
            if (empty($assetName)) {
                $errors[] = "Asset Name is required.";
            }

            // 2. Screen Category resolution
            $categoryInput = $rowData['screen_category'] ?? '';
            $matchedCategory = null;
            if ($categoryInput) {
                $normCat = Str::lower($categoryInput);
                $matchedCategory = $categories->first(function ($cat) use ($normCat) {
                    return Str::lower($cat->name) === $normCat || Str::lower($cat->slug) === $normCat;
                });
            }
            if (!$matchedCategory) {
                $errors[] = "Screen Category '{$categoryInput}' not found in master data.";
            }

            // 3. Location Resolution (Country -> State -> City)
            $countryInput = $rowData['country'] ?? '';
            $matchedCountry = null;
            if ($countryInput) {
                $matchedCountry = $countries->get(Str::lower($countryInput));
            }
            if (!$matchedCountry) {
                $errors[] = "Country '{$countryInput}' not found in database.";
            }

            $stateInput = $rowData['state'] ?? '';
            $matchedState = null;
            $willCreateState = false;
            if ($matchedCountry && $stateInput) {
                $normState = Str::lower($stateInput);
                $matchedState = $states->first(function ($s) use ($matchedCountry, $normState) {
                    return $s->country_id == $matchedCountry->id && (Str::lower($s->name) === $normState || Str::lower($s->code) === $normState);
                });

                if (!$matchedState && $autoCreateLocations) {
                    $willCreateState = true;
                    $warnings[] = "State '{$stateInput}' not found in database; will be auto-created under Country '{$matchedCountry->name}'.";
                }
            }
            if ($stateInput && !$matchedState && !$willCreateState) {
                $errors[] = "State '{$stateInput}' not found under Country '{$countryInput}'.";
            }

            $cityInput = $rowData['city'] ?? '';
            $matchedCity = null;
            $willCreateCity = false;
            if ($matchedCountry && $cityInput) {
                $normCity = Str::lower($cityInput);
                $matchedCity = $cities->first(function ($c) use ($matchedCountry, $matchedState, $normCity) {
                    if ($c->country_id != $matchedCountry->id) return false;
                    if ($matchedState && $c->state_id && $c->state_id != $matchedState->id) return false;
                    return Str::lower($c->name) === $normCity;
                });

                if (!$matchedCity && $autoCreateLocations) {
                    $willCreateCity = true;
                    $stateDisp = $matchedState ? $matchedState->name : ($stateInput ?: 'specified state');
                    $warnings[] = "City '{$cityInput}' not found in database; will be auto-created under State '{$stateDisp}'.";
                }
            }
            if ($cityInput && !$matchedCity && !$willCreateCity) {
                $errors[] = "City '{$cityInput}' not found under specified state/country.";
            }

            // 4. Location Name
            $locationName = $rowData['location_name'] ?? '';
            if (empty($locationName)) {
                $errors[] = "Location Name is required.";
            }

            // 5. Latitude & Longitude
            $lat = $rowData['latitude'] ?? null;
            if ($lat !== null && $lat !== '') {
                if (!is_numeric($lat) || $lat < -90 || $lat > 90) {
                    $errors[] = "Latitude must be a valid number between -90 and 90.";
                }
            }

            $lng = $rowData['longitude'] ?? null;
            if ($lng !== null && $lng !== '') {
                if (!is_numeric($lng) || $lng < -180 || $lng > 180) {
                    $errors[] = "Longitude must be a valid number between -180 and 180.";
                }
            }

            // 6. Revenue Split Calculation
            $illuvisionShare = $rowData['illuvision_percentage'] ?? null;
            $providerShare = $rowData['screen_provider_percentage'] ?? null;

            if ($illuvisionShare !== null && $illuvisionShare !== '' && $providerShare !== null && $providerShare !== '') {
                $illPct = (float) $illuvisionShare;
                $provPct = (float) $providerShare;
                if (round($illPct + $provPct, 2) !== 100.00) {
                    $errors[] = "Illuvision Share ({$illPct}%) + Provider Share ({$provPct}%) must equal 100%.";
                }
            } elseif ($illuvisionShare !== null && $illuvisionShare !== '') {
                $illPct = (float) $illuvisionShare;
                $provPct = 100.00 - $illPct;
                $rowData['screen_provider_percentage'] = $provPct;
            } elseif ($providerShare !== null && $providerShare !== '') {
                $provPct = (float) $providerShare;
                $illPct = 100.00 - $provPct;
                $rowData['illuvision_percentage'] = $illPct;
            } else {
                // Default fallback from selected provider
                $illPct = (float) ($provider->illuvision_percentage ?? 10.00);
                $provPct = (float) ($provider->screen_provider_percentage ?? 90.00);
                $rowData['illuvision_percentage'] = $illPct;
                $rowData['screen_provider_percentage'] = $provPct;
                $warnings[] = "Revenue split omitted; defaulting to Provider share ({$illPct}% / {$provPct}%).";
            }

            // 7. Screen Code & Duplicate Detection
            $screenCode = $rowData['screen_code'] ?? '';
            $isDuplicate = false;

            if ($screenCode) {
                $upperCode = Str::upper($screenCode);
                if (isset($existingCodes[$upperCode])) {
                    $msg = "Screen Code '{$screenCode}' already exists in database.";
                    if ($restrictDuplicates) {
                        $errors[] = $msg;
                    } else {
                        $warnings[] = $msg;
                    }
                    $isDuplicate = true;
                }
                if (isset($fileSeenCodes[$upperCode])) {
                    $msg = "Duplicate Screen Code '{$screenCode}' in this Excel file (Row {$fileSeenCodes[$upperCode]}).";
                    if ($restrictDuplicates) {
                        $errors[] = $msg;
                    } else {
                        $warnings[] = $msg;
                    }
                    $isDuplicate = true;
                }
                $fileSeenCodes[$upperCode] = $rowNumber;
            }

            if ($assetName && ($matchedCity || $willCreateCity) && $locationName) {
                $cityKeyPart = $matchedCity ? ('id:' . $matchedCity->id) : ('name:' . Str::lower(trim($cityInput)));
                $uKey = Str::lower(trim($assetName)) . '|' . $cityKeyPart . '|' . Str::lower(trim($locationName));
                
                if (isset($existingUniqueKeys[$uKey])) {
                    $msg = "Asset with matching name & location already exists in database.";
                    if ($restrictDuplicates) {
                        $errors[] = $msg;
                    } else {
                        $warnings[] = $msg;
                    }
                    $isDuplicate = true;
                }
                if (isset($fileSeenUniqueKeys[$uKey])) {
                    $msg = "Duplicate Asset name & location in this Excel file (Row {$fileSeenUniqueKeys[$uKey]}).";
                    if ($restrictDuplicates) {
                        $errors[] = $msg;
                    } else {
                        $warnings[] = $msg;
                    }
                    $isDuplicate = true;
                }
                $fileSeenUniqueKeys[$uKey] = $rowNumber;
            }

            // Status, Orientation, Resolution, Operating Hours defaults
            $status = Str::lower($rowData['status'] ?? 'active');
            if (!in_array($status, ['active', 'inactive', 'maintenance'])) {
                $status = 'active';
            }

            $orientation = Str::lower($rowData['orientation'] ?? 'landscape');
            if (!in_array($orientation, ['landscape', 'portrait', 'square'])) {
                $orientation = 'landscape';
            }

            $isValid = empty($errors);
            if ($isDuplicate) {
                $duplicateRowsCount++;
            }
            if ($isValid) {
                $validRowsCount++;
            } else {
                $invalidRowsCount++;
            }

            $parsedRows[] = [
                'row_number' => $rowNumber,
                'raw_data' => $rowData,
                'validated_payload' => $isValid ? [
                    'name' => $assetName,
                    'screen_code' => $screenCode ?: null,
                    'screen_category_id' => $matchedCategory?->id,
                    'screen_type' => $matchedCategory?->name,
                    'asset_type' => 'fixed',
                    'pricing_unit' => 'per_screen',
                    'fleet_size' => 1,
                    'country_id' => $matchedCountry?->id,
                    'state_id' => $matchedState?->id,
                    'state_name' => $stateInput ?: null,
                    'city_id' => $matchedCity?->id,
                    'city_name' => $cityInput ?: null,
                    'location_name' => $locationName,
                    'latitude' => $lat !== null && $lat !== '' ? (float)$lat : null,
                    'longitude' => $lng !== null && $lng !== '' ? (float)$lng : null,
                    'owner_type' => Screen::OWNER_TYPE_PROVIDER,
                    'screen_provider_id' => $providerId,
                    'pricing_plan_id' => $pricingPlanId,
                    'illuvision_percentage' => (float) $rowData['illuvision_percentage'],
                    'screen_provider_percentage' => (float) $rowData['screen_provider_percentage'],
                    'status' => $status,
                    'orientation' => $orientation,
                    'resolution' => $rowData['resolution'] ?? '1920x1080',
                    'operating_hours' => $rowData['operating_hours'] ?? '24/7',
                ] : null,
                'resolved_names' => [
                    'category' => $matchedCategory?->name ?? $categoryInput,
                    'country' => $matchedCountry?->name ?? $countryInput,
                    'state' => $matchedState?->name ?? $stateInput,
                    'city' => $matchedCity?->name ?? $cityInput,
                ],
                'is_valid' => $isValid,
                'is_duplicate' => $isDuplicate,
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }

        return [
            'success' => true,
            'header_mapping' => $headerMap,
            'unmapped_headers' => array_values($unmappedHeaders),
            'summary' => [
                'total_rows' => $totalRows,
                'valid_rows' => $validRowsCount,
                'invalid_rows' => $invalidRowsCount,
                'duplicate_rows' => $duplicateRowsCount,
            ],
            'provider' => [
                'id' => $provider->id,
                'name' => $provider->provider_name,
            ],
            'pricing_plan' => [
                'id' => $pricingPlan->id,
                'name' => $pricingPlan->name,
            ],
            'rows' => $parsedRows,
        ];
    }

    /**
     * Execute transactional confirmation & import of validated rows.
     */
    public function confirmImport(
        array $rows,
        int $providerId,
        int $pricingPlanId,
        bool $autoCreateLocations = true
    ): array {
        return DB::transaction(function () use ($rows, $providerId, $pricingPlanId, $autoCreateLocations) {
            $createdAssets = [];
            $importedCount = 0;

            $stateCache = [];
            $cityCache = [];

            foreach ($rows as $item) {
                if (empty($item['validated_payload'])) {
                    continue;
                }

                $payload = $item['validated_payload'];

                // 1. Resolve or auto-create State if needed
                $countryId = $payload['country_id'] ?? null;
                $stateId = $payload['state_id'] ?? null;
                $stateName = $payload['state_name'] ?? null;

                if (!$stateId && $countryId && $stateName && $autoCreateLocations) {
                    $cacheKey = $countryId . '|' . Str::lower(trim($stateName));
                    if (isset($stateCache[$cacheKey])) {
                        $stateId = $stateCache[$cacheKey];
                    } else {
                        $code = Str::upper(substr(preg_replace('/[^A-Za-z0-9]/', '', $stateName), 0, 3)) ?: 'ST';
                        $state = State::firstOrCreate(
                            ['country_id' => $countryId, 'name' => trim($stateName)],
                            ['code' => $code, 'is_active' => true]
                        );
                        $stateId = $state->id;
                        $stateCache[$cacheKey] = $stateId;
                    }
                    $payload['state_id'] = $stateId;
                }

                // 2. Resolve or auto-create City if needed
                $cityId = $payload['city_id'] ?? null;
                $cityName = $payload['city_name'] ?? null;

                if (!$cityId && $countryId && $cityName && $autoCreateLocations) {
                    $cacheKey = $countryId . '|' . ($stateId ?? 0) . '|' . Str::lower(trim($cityName));
                    if (isset($cityCache[$cacheKey])) {
                        $cityId = $cityCache[$cacheKey];
                    } else {
                        $city = City::firstOrCreate(
                            [
                                'country_id' => $countryId,
                                'state_id' => $stateId,
                                'name' => trim($cityName),
                            ],
                            ['is_active' => true]
                        );
                        $cityId = $city->id;
                        $cityCache[$cacheKey] = $cityId;
                    }
                    $payload['city_id'] = $cityId;
                }

                unset($payload['state_name'], $payload['city_name']);

                $payload['owner_type'] = Screen::OWNER_TYPE_PROVIDER;
                $payload['screen_provider_id'] = $providerId;
                $payload['pricing_plan_id'] = $pricingPlanId;
                $payload['asset_type'] = 'fixed';

                $asset = $this->assetService->createAsset($payload);
                $createdAssets[] = $asset->id;
                $importedCount++;
            }

            return [
                'success' => true,
                'imported_count' => $importedCount,
                'created_ids' => $createdAssets,
                'message' => "Successfully imported {$importedCount} Fixed Assets.",
            ];
        });
    }

    /**
     * Generate template headers and sample row.
     */
    public function getTemplateHeaders(): array
    {
        return [
            'Asset Name',
            'Screen Category',
            'Country',
            'State',
            'City',
            'Location Name',
            'Latitude',
            'Longitude',
            'Screen Code',
            'Illuvision Share (%)',
            'Provider Share (%)',
            'Status',
            'Orientation',
            'Resolution',
            'Operating Hours',
        ];
    }
}
