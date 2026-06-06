<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\Loan;
use App\Support\BusinessDay;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class LoanExportService
{
    /**
     * @return list<string>
     */
    public static function csvHeaders(): array
    {
        return [
            'loan_number',
            'member_number',
            'member_name',
            'tier',
            'amount_requested',
            'amount_approved',
            'member_portion',
            'master_portion',
            'status',
            'applied_at',
            'approved_at',
            'disbursed_at',
            'installments_total',
            'installments_paid',
            'min_monthly_installment',
            'guarantor_member_number',
            'guarantor_name',
        ];
    }

    public function downloadCsv(): StreamedResponse
    {
        $filename = 'loans-'.BusinessDay::now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, self::csvHeaders());

            Loan::query()
                ->with(['member', 'loanTier', 'guarantor'])
                ->withCount(['installments as installments_total'])
                ->withCount(['installments as installments_paid' => fn ($query) => $query->where('status', 'paid')])
                ->orderByDesc('id')
                ->each(function (Loan $loan) use ($handle): void {
                    fputcsv($handle, $this->csvRow($loan));
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    /**
     * @return list<int|float|string|null>
     */
    private function csvRow(Loan $loan): array
    {
        return [
            $loan->id,
            $loan->member?->member_number,
            $loan->member?->name,
            $loan->loanTier?->label,
            $loan->amount_requested,
            $loan->amount_approved,
            $loan->member_portion,
            $loan->master_portion,
            $loan->status,
            $loan->applied_at?->toDateString(),
            $loan->approved_at?->toDateString(),
            $loan->disbursed_at?->toDateString(),
            $loan->installments_total,
            $loan->installments_paid,
            $loan->loanTier?->min_monthly_installment,
            $loan->guarantor?->member_number,
            $loan->guarantor?->name,
        ];
    }
}
