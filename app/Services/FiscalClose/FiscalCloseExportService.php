<?php

declare(strict_types=1);

namespace App\Services\FiscalClose;

use App\Models\Tenant\FiscalClose;
use App\Models\Tenant\FiscalCloseMemberSnapshot;
use App\Models\Tenant\Transaction;
use App\Support\BusinessDay;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class FiscalCloseExportService
{
    private const DISK = 'local';

    /**
     * @return array<string, mixed>
     */
    public function generateAll(FiscalClose $close): array
    {
        if (
            ! in_array($close->status, [
                FiscalClose::STATUS_SNAPSHOT,
                FiscalClose::STATUS_PENDING_APPROVAL,
                FiscalClose::STATUS_ROLLED_FORWARD,
                FiscalClose::STATUS_PURGED,
            ], true)
        ) {
            throw new InvalidArgumentException(__('Exports require a certified snapshot.'));
        }

        $manifest = [
            'generated_at' => BusinessDay::now()->toIso8601String(),
            'fiscal_year_label' => $close->fiscal_year_label,
            'period_end' => $close->period_end->toDateString(),
            'files' => [],
        ];

        $manifest['files']['gl'] = $this->exportGl($close);
        $manifest['files']['arrears_aging'] = $this->exportArrearsAging($close);
        $manifest['files']['loan_portfolio'] = $this->exportLoanPortfolio($close);
        $manifest['files']['readiness_report'] = $this->exportReadinessReport($close);

        $close->update(['export_manifest_json' => $manifest]);

        return $manifest;
    }

    public function exportGl(FiscalClose $close): string
    {
        $path = $this->basePath($close).'/gl.csv';
        $periodEnd = $close->period_end->copy()->endOfDay();

        $handle = fopen($this->absolutePath($path), 'w');

        if ($handle === false) {
            throw new InvalidArgumentException(__('Unable to create GL export file.'));
        }

        fputcsv($handle, [
            __('ID'),
            __('Account ID'),
            __('Member ID'),
            __('Type'),
            __('Amount'),
            __('Balance after'),
            __('Reference type'),
            __('Reference ID'),
            __('Description'),
            __('Transacted at'),
        ]);

        Transaction::query()
            ->where('transacted_at', '<=', $periodEnd)
            ->orderBy('id')
            ->chunkById(500, function ($transactions) use ($handle): void {
                foreach ($transactions as $transaction) {
                    fputcsv($handle, [
                        $transaction->id,
                        $transaction->account_id,
                        $transaction->member_id,
                        $transaction->type,
                        number_format((float) $transaction->amount, 2, '.', ''),
                        number_format((float) $transaction->balance_after, 2, '.', ''),
                        $transaction->reference_type,
                        $transaction->reference_id,
                        $transaction->description,
                        $transaction->transacted_at?->toIso8601String(),
                    ]);
                }
            });

        fclose($handle);

        return $path;
    }

    public function exportArrearsAging(FiscalClose $close): string
    {
        $path = $this->basePath($close).'/arrears-aging.csv';

        $handle = fopen($this->absolutePath($path), 'w');

        if ($handle === false) {
            throw new InvalidArgumentException(__('Unable to create arrears export file.'));
        }

        fputcsv($handle, [
            __('Member ID'),
            __('Member'),
            __('Period'),
            __('Amount due'),
            __('Amount collected'),
            __('Collection status'),
            __('Late fee'),
        ]);

        FiscalCloseMemberSnapshot::query()
            ->where('fiscal_close_id', $close->id)
            ->with('member')
            ->orderBy('member_id')
            ->chunkById(200, function ($snapshots) use ($handle): void {
                foreach ($snapshots as $snapshot) {
                    foreach ($snapshot->contribution_arrears_json ?? [] as $arrear) {
                        fputcsv($handle, [
                            $snapshot->member_id,
                            $snapshot->member?->name,
                            $arrear['period'] ?? '',
                            number_format((float) ($arrear['amount_due'] ?? 0), 2, '.', ''),
                            number_format((float) ($arrear['amount_collected'] ?? 0), 2, '.', ''),
                            $arrear['collection_status'] ?? '',
                            number_format((float) ($arrear['late_fee_amount'] ?? 0), 2, '.', ''),
                        ]);
                    }
                }
            });

        fclose($handle);

        return $path;
    }

    public function exportLoanPortfolio(FiscalClose $close): string
    {
        $path = $this->basePath($close).'/loan-portfolio.csv';

        $handle = fopen($this->absolutePath($path), 'w');

        if ($handle === false) {
            throw new InvalidArgumentException(__('Unable to create loan portfolio export file.'));
        }

        fputcsv($handle, [
            __('Member ID'),
            __('Member'),
            __('Loan ID'),
            __('Principal'),
            __('Outstanding'),
            __('Status'),
            __('Installments JSON'),
        ]);

        FiscalCloseMemberSnapshot::query()
            ->where('fiscal_close_id', $close->id)
            ->with('member')
            ->orderBy('member_id')
            ->chunkById(200, function ($snapshots) use ($handle): void {
                foreach ($snapshots as $snapshot) {
                    foreach ($snapshot->loans_json ?? [] as $loan) {
                        fputcsv($handle, [
                            $snapshot->member_id,
                            $snapshot->member?->name,
                            $loan['loan_id'] ?? '',
                            number_format((float) ($loan['principal'] ?? 0), 2, '.', ''),
                            number_format((float) ($loan['outstanding'] ?? 0), 2, '.', ''),
                            $loan['status'] ?? '',
                            json_encode($loan['installments'] ?? [], JSON_THROW_ON_ERROR),
                        ]);
                    }
                }
            });

        fclose($handle);

        return $path;
    }

    public function exportReadinessReport(FiscalClose $close): string
    {
        $path = $this->basePath($close).'/readiness-report.json';
        $payload = $close->readiness_report_json ?? [];

        Storage::disk(self::DISK)->put(
            $path,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );

        return $path;
    }

    public function resolveDownloadPath(FiscalClose $close, string $fileKey): string
    {
        $manifest = $close->export_manifest_json ?? [];
        $relativePath = $manifest['files'][$fileKey] ?? null;

        if (! is_string($relativePath) || blank($relativePath)) {
            throw new InvalidArgumentException(__('Export file is not available.'));
        }

        if (! Storage::disk(self::DISK)->exists($relativePath)) {
            throw new InvalidArgumentException(__('Export file is missing from storage.'));
        }

        return $relativePath;
    }

    private function basePath(FiscalClose $close): string
    {
        return 'fiscal-closes/'.$close->id;
    }

    private function absolutePath(string $relativePath): string
    {
        Storage::disk(self::DISK)->makeDirectory(dirname($relativePath));

        return Storage::disk(self::DISK)->path($relativePath);
    }
}
