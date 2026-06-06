<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanRepayment;
use App\Support\BusinessDay;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class LoanRepaymentExportService
{
    /**
     * @return list<string>
     */
    public static function csvHeaders(): array
    {
        return [
            'repayment_type',
            'loan_number',
            'member_number',
            'member_name',
            'member_email',
            'installment_number',
            'amount',
            'late_fee_amount',
            'paid_at',
            'due_date',
            'notes',
        ];
    }

    public function downloadCsv(): StreamedResponse
    {
        $filename = 'loan-repayments-'.BusinessDay::now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, self::csvHeaders());

            LoanRepayment::query()
                ->with(['loan.member'])
                ->orderByDesc('paid_at')
                ->orderByDesc('id')
                ->each(function (LoanRepayment $repayment) use ($handle): void {
                    fputcsv($handle, $this->legacyRow($repayment));
                });

            LoanInstallment::query()
                ->with(['loan.member'])
                ->where('status', 'paid')
                ->orderByDesc('paid_at')
                ->orderByDesc('id')
                ->each(function (LoanInstallment $installment) use ($handle): void {
                    fputcsv($handle, $this->installmentRow($installment));
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
    private function legacyRow(LoanRepayment $repayment): array
    {
        $loan = $repayment->loan;

        return [
            'legacy',
            $loan?->getKey(),
            $loan?->member?->member_number,
            $loan?->member?->name,
            $loan?->member?->email,
            null,
            $repayment->amount,
            null,
            $repayment->paid_at?->toDateTimeString(),
            null,
            $repayment->notes,
        ];
    }

    /**
     * @return list<int|float|string|null>
     */
    private function installmentRow(LoanInstallment $installment): array
    {
        $loan = $installment->loan;

        return [
            'installment',
            $loan?->getKey(),
            $loan?->member?->member_number,
            $loan?->member?->name,
            $loan?->member?->email,
            $installment->installment_number,
            $installment->amount,
            $installment->late_fee_amount,
            $installment->paid_at?->toDateTimeString(),
            $installment->due_date?->format('Y-m-d'),
            null,
        ];
    }
}
