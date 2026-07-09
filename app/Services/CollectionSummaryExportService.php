<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Support\ContributionCollectionSummaryState;
use App\Support\Utf8CsvStream;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CollectionSummaryExportService
{
    public function __construct(
        protected ContributionCycleService $cycles,
    ) {}

    /**
     * CSV export for a contribution period (collection summary per spec).
     */
    public function downloadCsv(int $month, int $year): StreamedResponse
    {
        $period = Contribution::periodDate($month, $year);
        $filename = 'contributions-summary-'.$year.'-'.sprintf('%02d', $month).'.csv';

        return response()->streamDownload(function () use ($period, $month, $year): void {
            $handle = Utf8CsvStream::open();
            fputcsv($handle, [
                __('Member #'),
                __('Member'),
                __('Amount due'),
                __('Collected'),
                __('Outstanding'),
                __('State'),
                __('Status'),
                __('Collection status'),
                __('Late fee'),
                __('Cash balance'),
            ]);

            $memberIds = $this->cycles->summaryExportMemberIds($month, $year);

            if ($memberIds === []) {
                fclose($handle);

                return;
            }

            Member::query()
                ->contributionCycleEligible()
                ->whereIn('id', $memberIds)
                ->with(['cashAccount'])
                ->orderBy('member_number')
                ->each(function (Member $member) use ($handle, $period, $month, $year): void {
                    if (ContributionCollectionSummaryState::isExcludedFromSummaryExport($member, $month, $year)) {
                        return;
                    }

                    $contribution = Contribution::query()
                        ->where('member_id', $member->id)
                        ->where('period', $period)
                        ->first();

                    $due = (float) ($contribution?->amount_due ?? $member->monthly_contribution_amount);
                    $collected = (float) ($contribution?->amount_collected ?? 0);
                    $outstanding = max(0.0, $due - $collected);
                    $state = ContributionCollectionSummaryState::resolve($member, $month, $year, $contribution);
                    $status = $contribution?->status ?? 'missing';
                    $collectionStatus = $contribution?->collection_status ?? 'pending';

                    fputcsv($handle, [
                        $member->member_number,
                        $member->name,
                        number_format($due, 2, '.', ''),
                        number_format($collected, 2, '.', ''),
                        number_format($outstanding, 2, '.', ''),
                        $state,
                        $status,
                        $collectionStatus,
                        number_format((float) ($contribution?->late_fee_amount ?? 0), 2, '.', ''),
                        number_format($member->getCashBalance(), 2, '.', ''),
                    ]);
                });

            fclose($handle);
        }, $filename, Utf8CsvStream::downloadHeaders());
    }
}
