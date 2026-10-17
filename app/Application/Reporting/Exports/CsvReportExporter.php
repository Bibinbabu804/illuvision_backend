<?php

namespace App\Application\Reporting\Exports;

use App\Application\Reporting\Exports\Contracts\ReportExporter;
use App\Support\DurationFormatter;

/**
 * CSV renderer for report results.
 *
 * Column names, column ordering, header rows and the blank separator row used by
 * the rewards export are part of the existing export contract and are preserved
 * byte-for-byte. `xlsx` is accepted by the endpoint validation but has never been
 * implemented; it is not added here.
 */
class CsvReportExporter implements ReportExporter
{
    /**
     * {@inheritdoc}
     */
    public function export(string $type, array $data): array
    {
        $output = fopen('php://temp', 'r+');

        switch ($type) {
            case 'bookings':
                fputcsv($output, ['Date', 'Bookings', 'Revenue']);
                if (isset($data['daily_volumes'])) {
                    foreach ($data['daily_volumes'] as $row) {
                        fputcsv($output, [$row['date'], $row['bookings'], $row['revenue']]);
                    }
                }
                break;

            case 'revenue':
                fputcsv($output, ['Date', 'Revenue', 'Bookings']);
                if (isset($data['daily_revenue'])) {
                    foreach ($data['daily_revenue'] as $row) {
                        fputcsv($output, [$row['date'], $row['revenue'], $row['bookings']]);
                    }
                }
                break;

            case 'screens':
                fputcsv($output, ['Screen', 'City', 'Occupancy %', 'Bookings', 'Revenue']);
                if (isset($data['screen_performance'])) {
                    foreach ($data['screen_performance'] as $row) {
                        fputcsv($output, [
                            $row['screen_name'],
                            $row['city_name'],
                            $row['occupancy_pct'],
                            $row['booking_count'],
                            $row['revenue'],
                        ]);
                    }
                }
                break;

            case 'cities':
                fputcsv($output, ['City', 'Screens', 'Bookings', 'Revenue', 'Avg Revenue/Screen']);
                if (isset($data['city_performance'])) {
                    foreach ($data['city_performance'] as $row) {
                        fputcsv($output, [
                            $row['city_name'],
                            $row['screen_count'],
                            $row['booking_count'],
                            $row['revenue'],
                            $row['avg_revenue_per_screen'],
                        ]);
                    }
                }
                break;

            case 'categories':
                fputcsv($output, ['Category', 'Bookings', 'Revenue']);
                if (isset($data['revenue_by_category'])) {
                    foreach ($data['revenue_by_category'] as $row) {
                        fputcsv($output, [$row['name'], $row['bookings'], $row['revenue']]);
                    }
                }
                break;

            case 'campaigns':
                fputcsv($output, ['Campaign', 'Advertiser', 'Status', 'Duration', 'Duration (Minutes)', 'Duration (Days)', 'Screens', 'Amount']);
                if (isset($data['campaigns'])) {
                    foreach ($data['campaigns'] as $row) {
                        fputcsv($output, [
                            $row['title'],
                            $row['advertiser_name'],
                            $row['status'],
                            $row['duration_label'] ?? ($row['duration_minutes'] ? DurationFormatter::formatMinutes($row['duration_minutes']) : $row['duration_days'].' Days'),
                            $row['duration_minutes'] ?? round($row['duration_days'] * 1440),
                            $row['duration_days'],
                            $row['total_screens'],
                            $row['final_amount'],
                        ]);
                    }
                }
                break;

            case 'screen-providers':
                fputcsv($output, ['Provider', 'Owned Screens', 'Campaigns', 'Gross Revenue', 'Platform Revenue', 'Provider Revenue', 'Pending Settlement', 'Settled Amount', 'Outstanding']);
                if (isset($data['provider_breakdown'])) {
                    foreach ($data['provider_breakdown'] as $row) {
                        fputcsv($output, [
                            $row['business_name'] ?: $row['provider_name'],
                            $row['owned_screens'],
                            $row['campaign_count'],
                            $row['gross_revenue'],
                            $row['platform_revenue'],
                            $row['provider_revenue'],
                            $row['pending_settlement'],
                            $row['settled_amount'],
                            $row['outstanding_balance'],
                        ]);
                    }
                }
                break;

            case 'referrals':
                fputcsv($output, ['Partner', 'Code', 'Usage Count', 'Total Discount', 'Total Commission']);
                if (isset($data['top_codes'])) {
                    foreach ($data['top_codes'] as $row) {
                        fputcsv($output, [
                            $row['partner_name'],
                            $row['code'],
                            $row['usage_count'],
                            $row['total_discount'],
                            $row['total_commission'],
                        ]);
                    }
                }
                break;

            case 'rewards':
                fputcsv($output, ['Customer', 'Email', 'Balance', 'Lifetime Earned', 'Lifetime Redeemed', 'Lifetime Expired', 'Redemption Rate', 'Period Issued', 'Period Redeemed']);
                // Export summary KPIs first
                if (isset($data['kpis'])) {
                    fputcsv($output, [
                        'SUMMARY',
                        '',
                        $data['kpis']['total_accounts'] ?? 0,
                        $data['kpis']['total_issued'] ?? 0,
                        $data['kpis']['total_redeemed'] ?? 0,
                        $data['kpis']['total_expired'] ?? 0,
                        ($data['kpis']['redemption_rate'] ?? 0).'%',
                        $data['kpis']['period_issued'] ?? 0,
                        $data['kpis']['period_redeemed'] ?? 0,
                    ]);
                }
                // Then export top customers
                fputcsv($output, []); // Blank separator row
                fputcsv($output, ['TOP CUSTOMERS', '', 'Balance', 'Lifetime Earned', 'Lifetime Redeemed', 'Lifetime Expired', '', '', '']);
                if (isset($data['top_customers'])) {
                    foreach ($data['top_customers'] as $row) {
                        fputcsv($output, [
                            $row['name'] ?? '',
                            $row['email'] ?? '',
                            $row['balance'] ?? 0,
                            $row['lifetime_earned'] ?? 0,
                            $row['lifetime_redeemed'] ?? 0,
                            $row['lifetime_expired'] ?? 0,
                            '',
                            '',
                            '',
                        ]);
                    }
                }
                break;
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return [
            'body' => $csv,
            'fileName' => "reports_{$type}_".now()->format('Ymd_His').'.csv',
            'contentType' => 'text/csv',
        ];
    }
}
