<?php

declare(strict_types=1);

namespace App\Services\Disbursement;

use App\Contracts\DisbursementFileExporter;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\DisbursementBatch;
use App\Models\Tenant\DisbursementBatchItem;
use App\Models\Tenant\OutboundPayment;
use App\Models\Tenant\User;
use App\Support\IbanValidator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

final class DisbursementBatchService
{
    public function createDraft(?User $creator = null, string $bankFormat = DisbursementBatch::FORMAT_AL_RAJHI_CSV): DisbursementBatch
    {
        if (!array_key_exists($bankFormat, DisbursementBatch::formatLabels())) {
            throw new InvalidArgumentException(__('Unsupported bank file format.'));
        }

        return DisbursementBatch::query()->create([
            'status' => DisbursementBatch::STATUS_DRAFT,
            'bank_format' => $bankFormat,
            'created_by' => $creator?->id,
        ]);
    }

    /**
     * Eligible pending outbound remittances with a valid IBAN that are not already in an active batch.
     *
     * @return Collection<int, OutboundPayment>
     */
    public function eligibleOutboundPayments(): Collection
    {
        $activeBatchIds = DisbursementBatch::query()
            ->whereNotIn('status', [
                DisbursementBatch::STATUS_CANCELLED,
                DisbursementBatch::STATUS_CLEARED,
            ])
            ->pluck('id');

        $usedOutboundIds = DisbursementBatchItem::query()
            ->whereIn('disbursement_batch_id', $activeBatchIds)
            ->where('status', DisbursementBatchItem::STATUS_INCLUDED)
            ->pluck('outbound_payment_id');

        return OutboundPayment::query()
            ->pending()
            ->whereNotIn('id', $usedOutboundIds)
            ->orderBy('id')
            ->get()
            ->filter(function (OutboundPayment $payment): bool {
                $iban = IbanValidator::normalize($payment->payee_iban);

                return $iban !== null && IbanValidator::isValid($iban);
            })
            ->values();
    }

    /**
     * @param  list<int>  $outboundPaymentIds
     */
    public function addEligibleItems(DisbursementBatch $batch, array $outboundPaymentIds): DisbursementBatch
    {
        if ($batch->status !== DisbursementBatch::STATUS_DRAFT) {
            throw new InvalidArgumentException(__('Only draft batches can accept new items.'));
        }

        $eligible = $this->eligibleOutboundPayments()->keyBy('id');

        DB::transaction(function () use ($batch, $outboundPaymentIds, $eligible): void {
            foreach ($outboundPaymentIds as $id) {
                $payment = $eligible->get((int) $id);

                if ($payment === null) {
                    continue;
                }

                $iban = IbanValidator::normalize($payment->payee_iban);

                if ($iban === null || !IbanValidator::isValid($iban)) {
                    continue;
                }

                DisbursementBatchItem::query()->updateOrCreate(
                    [
                        'disbursement_batch_id' => $batch->id,
                        'outbound_payment_id' => $payment->id,
                    ],
                    [
                        'amount' => $payment->amount,
                        'payee_name' => $payment->payee_name,
                        'payee_iban' => $iban,
                        'status' => DisbursementBatchItem::STATUS_INCLUDED,
                    ],
                );
            }

            $this->recalculateTotals($batch);
        });

        return $batch->fresh(['items']) ?? $batch;
    }

    public function submitForApproval(DisbursementBatch $batch): DisbursementBatch
    {
        if ($batch->status !== DisbursementBatch::STATUS_DRAFT) {
            throw new InvalidArgumentException(__('Only draft batches can be submitted for approval.'));
        }

        if ($batch->items()->where('status', DisbursementBatchItem::STATUS_INCLUDED)->count() === 0) {
            throw new InvalidArgumentException(__('Add at least one valid remittance before submitting.'));
        }

        $batch->forceFill(['status' => DisbursementBatch::STATUS_PENDING_APPROVAL])->save();

        return $batch->fresh() ?? $batch;
    }

    public function approve(DisbursementBatch $batch, User $approver): DisbursementBatch
    {
        if ($batch->status !== DisbursementBatch::STATUS_PENDING_APPROVAL) {
            throw new InvalidArgumentException(__('Only batches pending approval can be approved.'));
        }

        if ((int) $batch->created_by === (int) $approver->id) {
            throw new InvalidArgumentException(__('The batch builder cannot also approve the batch.'));
        }

        $batch->forceFill([
            'status' => DisbursementBatch::STATUS_APPROVED,
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ])->save();

        return $batch->fresh() ?? $batch;
    }

    public function generateFile(DisbursementBatch $batch): DisbursementBatch
    {
        if (!in_array($batch->status, [DisbursementBatch::STATUS_APPROVED, DisbursementBatch::STATUS_GENERATED], true)) {
            throw new InvalidArgumentException(__('Approve the batch before generating a bank file.'));
        }

        $exporter = $this->exporterFor($batch->bank_format);
        $export = $exporter->export($batch->load('items'));
        $path = sprintf('disbursement-batches/%d/batch-%d.%s', $batch->id, $batch->id, $export['extension']);

        Storage::disk('local')->put($path, $export['contents']);

        $batch->forceFill([
            'status' => DisbursementBatch::STATUS_GENERATED,
            'file_disk_path' => $path,
            'file_sha256' => hash('sha256', $export['contents']),
            'generated_at' => now(),
        ])->save();

        return $batch->fresh() ?? $batch;
    }

    /**
     * @return array{path: string, sha256: string, download_name: string, mime: string}
     */
    public function downloadMeta(DisbursementBatch $batch): array
    {
        if (
            $batch->status !== DisbursementBatch::STATUS_GENERATED
            && $batch->status !== DisbursementBatch::STATUS_ACKED
            && $batch->status !== DisbursementBatch::STATUS_CLEARED
        ) {
            throw new InvalidArgumentException(__('Generate the bank file before downloading.'));
        }

        if (!filled($batch->file_disk_path) || !Storage::disk('local')->exists($batch->file_disk_path)) {
            throw new InvalidArgumentException(__('Batch file is missing.'));
        }

        $contents = Storage::disk('local')->get($batch->file_disk_path);
        $sha = hash('sha256', $contents);

        if ($batch->file_sha256 && !hash_equals($batch->file_sha256, $sha)) {
            throw new InvalidArgumentException(__('Batch file hash mismatch.'));
        }

        $extension = pathinfo($batch->file_disk_path, PATHINFO_EXTENSION) ?: 'csv';

        return [
            'path' => $batch->file_disk_path,
            'sha256' => $sha,
            'download_name' => 'disbursement-batch-' . $batch->id . '.' . $extension,
            'mime' => $extension === 'xml' ? 'application/xml' : 'text/csv',
        ];
    }

    public function markItemClearedFromBankTransaction(BankTransaction $transaction): void
    {
        $outbound = OutboundPayment::query()
            ->where('bank_transaction_id', $transaction->id)
            ->first();

        if ($outbound === null) {
            return;
        }

        $items = DisbursementBatchItem::query()
            ->where('outbound_payment_id', $outbound->id)
            ->where('status', DisbursementBatchItem::STATUS_INCLUDED)
            ->get();

        foreach ($items as $item) {
            $item->forceFill(['status' => DisbursementBatchItem::STATUS_CLEARED])->save();
            $this->refreshBatchClearanceStatus($item->batch);
        }
    }

    private function refreshBatchClearanceStatus(?DisbursementBatch $batch): void
    {
        if ($batch === null) {
            return;
        }

        $included = $batch->items()->where('status', DisbursementBatchItem::STATUS_INCLUDED)->count();
        $cleared = $batch->items()->where('status', DisbursementBatchItem::STATUS_CLEARED)->count();

        if ($cleared > 0 && $included === 0) {
            $batch->forceFill(['status' => DisbursementBatch::STATUS_CLEARED])->save();
        }
    }

    private function recalculateTotals(DisbursementBatch $batch): void
    {
        $items = $batch->items()->where('status', DisbursementBatchItem::STATUS_INCLUDED)->get();

        $batch->forceFill([
            'item_count' => $items->count(),
            'total_amount' => round((float) $items->sum('amount'), 2),
        ])->save();
    }

    private function exporterFor(string $format): DisbursementFileExporter
    {
        return match ($format) {
            DisbursementBatch::FORMAT_AL_RAJHI_CSV => app(AlRajhiCsvDisbursementExporter::class),
            DisbursementBatch::FORMAT_PAIN001_XML => app(Pain001XmlDisbursementExporter::class),
            default => throw new InvalidArgumentException(__('Unsupported bank file format.')),
        };
    }
}
