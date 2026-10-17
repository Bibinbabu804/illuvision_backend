<?php

namespace App\Application\Reporting\Support;

final class GrowthRate
{
    /**
     * Percentage growth between two periods, rounded to 1 decimal place.
     *
     * Semantics preserved verbatim from ReportService::growthRate():
     *  - previous and current both zero -> 0
     *  - previous zero, current non-zero -> 100
     *  - otherwise -> ((current - previous) / previous) * 100
     *
     * The loose `==` comparison against 0 is retained on purpose: it treats any
     * falsy previous value as a zero baseline, which is what the dashboard has
     * always displayed. Tightening it to `===` would alter rendered growth
     * percentages.
     *
     * Note: the 0 and 100 literals are returned from a `float` return type in a
     * file without strict_types, so they are coerced to 0.0 and 100.0. That
     * coercion is part of the existing JSON payload shape and is preserved.
     */
    public static function between(float $previous, float $current): float
    {
        if ($previous == 0 && $current == 0) {
            return 0;
        }

        if ($previous == 0) {
            return 100;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
