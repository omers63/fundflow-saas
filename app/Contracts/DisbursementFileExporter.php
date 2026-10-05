<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Tenant\DisbursementBatch;

interface DisbursementFileExporter
{
    public function format(): string;

    /**
     * @return array{contents: string, extension: string, mime: string}
     */
    public function export(DisbursementBatch $batch): array;
}
