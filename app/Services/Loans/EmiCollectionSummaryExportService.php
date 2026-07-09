<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Services\ContributionCycleService;
use App\Support\InstallmentCollectionStatus;
use App\Support\Utf8CsvStream;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmiCollectionSummaryExportService
{
    public function __construct(
        protected ContributionCycleService $cycles,
        protected LoanEmiCollectionCatalogService $catalog,
    ) {}

    /**
     * CSV export for EMI collection readiness for a cycle period.
     */
    public function downloadCsv(int $month, int $year): StreamedResponse
    {
        [$start, $end] = $this->cycles->cycleDueDateBounds($month, $year);
        $filename = 'emi-collection-summary-'.$year.'-'.sprintf('%02d', $month).'.csv';

        return response()->streamDownload(function () use ($start, $end): void {
            $handle = Utf8CsvStream::open();
            fputcsv($handle, [
                __('Member #'),
                __('Member'),
                __('Loan #'),
                __('Installment #'),
                __('Due date'),
                __('Amount due'),
                __('Status'),
                __('Collection status'),
                __('Late fee'),
                __('Cash balance'),
                __('Cash shortfall'),
            ]);

            LoanInstallment::query()
                ->whereIn('status', ['pending', 'overdue'])
                ->where(function (Builder $query): void {
                    $query->whereNull('collection_status')
                        ->orWhereIn('collection_status', InstallmentCollectionStatus::openCollectionStates());
                })
                ->whereBetween('due_date', [$start, $end])
                ->whereHas('loan', fn (Builder $loan): Builder => $loan->whereIn('status', ['active', 'transferred']))
                ->with(['loan.member.cashAccount'])
                ->orderBy('due_date')
                ->orderBy('id')
                ->each(function (LoanInstallment $installment) use ($handle): void {
                    $loan = $installment->loan;
                    $member = $loan?->member;

                    if (! $member instanceof Member) {
                        return;
                    }

                    $amountDue = (float) $installment->amount;
                    $lateFee = (float) ($installment->late_fee_amount ?? 0);
                    $cash = $member->getCashBalance();
                    $required = $amountDue + max(0.0, $lateFee);
                    $shortfall = max(0.0, $required - $cash);

                    fputcsv($handle, [
                        $member->member_number,
                        $member->name,
                        $loan->id,
                        $installment->installment_number,
                        optional($installment->due_date)?->format('Y-m-d') ?? '',
                        number_format($amountDue, 2, '.', ''),
                        $installment->status,
                        $installment->collection_status ?? InstallmentCollectionStatus::PENDING,
                        number_format($lateFee, 2, '.', ''),
                        number_format($cash, 2, '.', ''),
                        number_format($shortfall, 2, '.', ''),
                    ]);
                });

            fclose($handle);
        }, $filename, Utf8CsvStream::downloadHeaders());
    }
}
