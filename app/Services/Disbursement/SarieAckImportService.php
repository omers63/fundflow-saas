<?php

declare(strict_types=1);

namespace App\Services\Disbursement;

use App\Models\Tenant\DisbursementBatch;
use App\Models\Tenant\DisbursementBatchItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Import bank acknowledgement (SARIE-style CSV or pain.002-lite XML).
 *
 * CSV columns: item_id|iban,status(accepted|rejected),reference,reason
 */
final class SarieAckImportService
{
    /**
     * @return array{accepted: int, rejected: int, path: string, sha256: string}
     */
    public function import(DisbursementBatch $batch, string $contents): array
    {
        if (
            !in_array($batch->status, [
                DisbursementBatch::STATUS_GENERATED,
                DisbursementBatch::STATUS_ACKED,
            ], true)
        ) {
            throw new InvalidArgumentException(__('Generate the disbursement file before importing a bank acknowledgement.'));
        }

        $path = sprintf('disbursement-batches/%d/ack-%s.txt', $batch->id, now()->format('YmdHis'));
        Storage::disk('local')->put($path, $contents);
        $sha = hash('sha256', $contents);

        $accepted = 0;
        $rejected = 0;

        $rows = $this->parse($contents);

        DB::transaction(function () use ($batch, $rows, &$accepted, &$rejected, $path, $sha): void {
            foreach ($rows as $row) {
                $item = $this->findItem($batch, $row);
                if ($item === null) {
                    continue;
                }

                $status = $row['status'];
                $item->forceFill([
                    'ack_status' => $status,
                    'ack_reference' => $row['reference'],
                    'ack_reason' => $row['reason'],
                    'status' => $status === 'rejected'
                        ? DisbursementBatchItem::STATUS_REJECTED
                        : $item->status,
                ])->save();

                if ($status === 'accepted') {
                    $accepted++;
                } else {
                    $rejected++;
                }
            }

            $batch->forceFill([
                'status' => DisbursementBatch::STATUS_ACKED,
                'acked_at' => now(),
                'ack_file_path' => $path,
                'ack_sha256' => $sha,
                'ack_accepted_count' => $accepted,
                'ack_rejected_count' => $rejected,
            ])->save();
        });

        return [
            'accepted' => $accepted,
            'rejected' => $rejected,
            'path' => $path,
            'sha256' => $sha,
        ];
    }

    /**
     * @return list<array{item_id: ?int, iban: ?string, status: string, reference: ?string, reason: ?string}>
     */
    private function parse(string $contents): array
    {
        $trimmed = trim($contents);
        if (str_starts_with($trimmed, '<?xml') || str_contains($trimmed, '<Document')) {
            return $this->parsePain002Lite($trimmed);
        }

        $rows = [];
        foreach (preg_split('/\R/', $trimmed) ?: [] as $i => $line) {
            $line = trim($line);
            if ($line === '' || ($i === 0 && str_contains(strtolower($line), 'status'))) {
                continue;
            }
            $cols = str_getcsv($line);
            if (count($cols) < 2) {
                continue;
            }

            $key = trim($cols[0]);
            $status = strtolower(trim($cols[1]));
            if (!in_array($status, ['accepted', 'rejected'], true)) {
                continue;
            }

            $rows[] = [
                'item_id' => ctype_digit($key) ? (int) $key : null,
                'iban' => ctype_digit($key) ? null : strtoupper(preg_replace('/\s+/', '', $key) ?? ''),
                'status' => $status,
                'reference' => $cols[2] ?? null,
                'reason' => $cols[3] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{item_id: ?int, iban: ?string, status: string, reference: ?string, reason: ?string}>
     */
    private function parsePain002Lite(string $xml): array
    {
        $rows = [];
        if (preg_match_all('/<TxSts>(ACCP|RJCT)<\/TxSts>.*?<EndToEndId>([^<]+)<\/EndToEndId>/s', $xml, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $rows[] = [
                    'item_id' => ctype_digit($match[2]) ? (int) $match[2] : null,
                    'iban' => null,
                    'status' => $match[1] === 'ACCP' ? 'accepted' : 'rejected',
                    'reference' => $match[2],
                    'reason' => null,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array{item_id: ?int, iban: ?string, status: string, reference: ?string, reason: ?string}  $row
     */
    private function findItem(DisbursementBatch $batch, array $row): ?DisbursementBatchItem
    {
        $query = DisbursementBatchItem::query()->where('disbursement_batch_id', $batch->id);

        if ($row['item_id'] !== null) {
            return $query->whereKey($row['item_id'])->first();
        }

        if (filled($row['iban'])) {
            return $query->where('payee_iban', $row['iban'])->first();
        }

        if (filled($row['reference']) && ctype_digit((string) $row['reference'])) {
            return $query->whereKey((int) $row['reference'])->first();
        }

        return null;
    }
}
