<?php

namespace App\Application\Reporting\Contracts;

interface ReportQuery
{
    /**
     * Execute the read query and return the report result structure.
     *
     * Reporting queries are read-only consumers of canonical business truth.
     * They must never mutate transactional state.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function execute(array $filters = []): array;
}
