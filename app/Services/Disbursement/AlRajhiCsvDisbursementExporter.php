<?php

declare(strict_types=1);

namespace App\Services\Disbursement;

use App\Contracts\DisbursementFileExporter;
use App\Models\Tenant\DisbursementBatch;
use App\Models\Tenant\DisbursementBatchItem;

final class AlRajhiCsvDisbursementExporter implements DisbursementFileExporter
{
    public function format(): string
    {
        return DisbursementBatch::FORMAT_AL_RAJHI_CSV;
    }

    public function export(DisbursementBatch $batch): array
    {
        $batch->loadMissing('items');

        $lines = [
            'Beneficiary Name,IBAN,Amount,Currency,Reference,Narration',
        ];

        foreach ($batch->items->where('status', DisbursementBatchItem::STATUS_INCLUDED) as $item) {
            $lines[] = implode(',', [
                self::csv($item->payee_name),
                self::csv($item->payee_iban),
                number_format((float) $item->amount, 2, '.', ''),
                'SAR',
                self::csv('BATCH-'.$batch->id.'-ITEM-'.$item->id),
                self::csv('Outbound '.$item->outbound_payment_id),
            ]);
        }

        return [
            'contents' => implode("\n", $lines)."\n",
            'extension' => 'csv',
            'mime' => 'text/csv',
        ];
    }

    private static function csv(string $value): string
    {
        $escaped = str_replace('"', '""', $value);

        return '"'.$escaped.'"';
    }
}
