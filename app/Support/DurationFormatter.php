<?php

namespace App\Support;

use Carbon\Carbon;

class DurationFormatter
{
    /**
     * Format a canonical duration in minutes into a human-readable string.
     *
     * Examples:
     *   30     -> "30 Minutes"
     *   45     -> "45 Minutes"
     *   60     -> "1 Hour"
     *   90     -> "1 Hour 30 Minutes"
     *   120    -> "2 Hours"
     *   135    -> "2 Hours 15 Minutes"
     *   1440   -> "1 Day"
     *   2880   -> "2 Days"
     *   43200  -> "1 Month"
     *   86400  -> "2 Months"
     */
    public static function formatMinutes(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        if ($minutes <= 0) {
            return '0 Minutes';
        }

        if ($minutes < 60) {
            return $minutes === 1 ? '1 Minute' : "{$minutes} Minutes";
        }

        if ($minutes < 1440) {
            $hours = (int) floor($minutes / 60);
            $mins = $minutes % 60;
            $hourStr = $hours === 1 ? '1 Hour' : "{$hours} Hours";

            if ($mins === 0) {
                return $hourStr;
            }

            $minStr = $mins === 1 ? '1 Minute' : "{$mins} Minutes";
            return "{$hourStr} {$minStr}";
        }

        // Standard 30-day month = 43,200 minutes
        if ($minutes % 43200 === 0) {
            $months = (int) ($minutes / 43200);
            return $months === 1 ? '1 Month' : "{$months} Months";
        }

        $days = (int) floor($minutes / 1440);
        $remMinutes = $minutes % 1440;
        $dayStr = $days === 1 ? '1 Day' : "{$days} Days";

        if ($remMinutes === 0) {
            return $dayStr;
        }

        $hours = (int) floor($remMinutes / 60);
        $mins = $remMinutes % 60;
        $parts = [$dayStr];

        if ($hours > 0) {
            $parts[] = $hours === 1 ? '1 Hour' : "{$hours} Hours";
        }
        if ($mins > 0) {
            $parts[] = $mins === 1 ? '1 Minute' : "{$mins} Minutes";
        }

        return implode(' ', $parts);
    }

    /**
     * Calculate canonical difference in minutes between two timestamps.
     */
    public static function calculateMinutes($startTime, $endTime): int
    {
        if (!$startTime || !$endTime) {
            return 0;
        }

        $start = $startTime instanceof Carbon ? $startTime : Carbon::parse($startTime);
        $end = $endTime instanceof Carbon ? $endTime : Carbon::parse($endTime);

        return max(1, (int) round($start->diffInMinutes($end)));
    }
}
