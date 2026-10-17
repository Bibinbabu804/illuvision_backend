<?php

namespace App\Application\Reporting\Support;

use App\Support\DurationFormatter;

/**
 * Thin indirection over the canonical duration label formatter.
 *
 * The legacy ReportService called \App\Support\DurationFormatter statically from
 * the reporting layer. Routing it through this class keeps reporting dependent
 * on a single named concept while still delegating to the one canonical
 * implementation, so no formatting logic is duplicated (ARCHITECTURE_RULES
 * Rule 7: Zero Business Logic Duplication).
 */
final class DurationLabelFormatter
{
    public static function formatMinutes(int $minutes): string
    {
        return DurationFormatter::formatMinutes($minutes);
    }
}
