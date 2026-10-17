<?php

namespace App\Application\Reporting\Exports\Contracts;

interface ReportExporter
{
    /**
     * Render an already-resolved report result into a downloadable payload.
     *
     * @param  array<string, mixed>  $data
     * @return array{body: string, fileName: string, contentType: string}
     */
    public function export(string $type, array $data): array;
}
