<?php

namespace App\Application\Reporting\Support;

use Carbon\Carbon;

final class ReportPeriod
{
    /**
     * Resolve the reporting period exactly as the legacy ReportService did.
     *
     * The asymmetry below is deliberate and load-bearing. When a filter is
     * supplied the caller's raw value is passed through untouched, because
     * ReportController::parseFilters() formats date_from as "Y-m-d" but
     * date_to as "Y-m-d H:i:s" (endOfDay). The defaults, by contrast, are
     * formatted as plain "Y-m-d". Normalising either side would move report
     * boundary dates and therefore change reported totals.
     *
     * @param  array<string, mixed>  $filters
     * @return array{date_from: mixed, date_to: mixed}
     */
    public static function resolve(array $filters): array
    {
        return [
            'date_from' => $filters['date_from'] ?? Carbon::now()->startOfMonth()->format('Y-m-d'),
            'date_to' => $filters['date_to'] ?? Carbon::now()->endOfMonth()->format('Y-m-d'),
        ];
    }
}
