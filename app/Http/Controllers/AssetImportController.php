<?php

namespace App\Http\Controllers;

use App\Services\AssetImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssetImportController extends Controller
{
    public function __construct(
        protected AssetImportService $importService
    ) {}

    /**
     * Download sample CSV template.
     */
    public function downloadTemplate(): StreamedResponse
    {
        $headers = $this->importService->getTemplateHeaders();
        $sampleRow = [
            'Lulu Mall Food Court Screen',
            'Restaurant Display',
            'India',
            'Kerala',
            'Kochi',
            'Lulu Mall Level 2 Food Court',
            '10.0261',
            '76.3125',
            'LULU-FC-01',
            '15.00',
            '85.00',
            'active',
            'landscape',
            '1920x1080',
            '09:00 - 23:00',
        ];

        $callback = function () use ($headers, $sampleRow) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $headers);
            fputcsv($file, $sampleRow);
            fclose($file);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="fixed_assets_import_template.csv"',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ]);
    }

    /**
     * Validate Excel file and return JSON preview.
     */
    public function validateImport(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:5120',
            'screen_provider_id' => 'required|exists:screen_providers,id',
            'pricing_plan_id' => 'required|exists:pricing_plans,id',
            'auto_create_locations' => 'nullable',
            'restrict_duplicates' => 'nullable',
        ]);

        $filePath = $request->file('file')->getRealPath();
        $providerId = (int) $request->input('screen_provider_id');
        $pricingPlanId = (int) $request->input('pricing_plan_id');
        $autoCreateLocations = filter_var($request->input('auto_create_locations', true), FILTER_VALIDATE_BOOLEAN);
        $restrictDuplicates = filter_var($request->input('restrict_duplicates', true), FILTER_VALIDATE_BOOLEAN);

        try {
            $result = $this->importService->validateImport(
                $filePath,
                $providerId,
                $pricingPlanId,
                $autoCreateLocations,
                $restrictDuplicates
            );
            return response()->json($result, $result['success'] ? 200 : 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to parse Excel file: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Confirm and execute transactional bulk import.
     */
    public function confirmImport(Request $request): JsonResponse
    {
        $request->validate([
            'screen_provider_id' => 'required|exists:screen_providers,id',
            'pricing_plan_id' => 'required|exists:pricing_plans,id',
            'auto_create_locations' => 'nullable',
            'rows' => 'required|array|min:1',
            'rows.*.validated_payload' => 'required|array',
        ]);

        $providerId = (int) $request->input('screen_provider_id');
        $pricingPlanId = (int) $request->input('pricing_plan_id');
        $autoCreateLocations = filter_var($request->input('auto_create_locations', true), FILTER_VALIDATE_BOOLEAN);
        $rows = $request->input('rows');

        try {
            $result = $this->importService->confirmImport(
                $rows,
                $providerId,
                $pricingPlanId,
                $autoCreateLocations
            );
            return response()->json($result, 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Import execution failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
