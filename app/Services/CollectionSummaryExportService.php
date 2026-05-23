<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Support\ContributionCollectionStatus;
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
        $filename = 'collection-summary-'.$year.'-'.sprintf('%02d', $month).'.csv';

        return response()->streamDownload(function () use ($period, $month, $year): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                __('Member #'),
                __('Member'),
                __('Amount due'),
                __('Collected'),
                __('Status'),
                __('Collection status'),
                __('Late fee'),
                __('Cash balance'),
            ]);

            Member::active()->orderBy('member_number')->each(function (Member $member) use ($handle, $period, $month, $year): void {
                if ($member->isExemptFromContributions($month, $year)) {
                    return;
                }

                $contribution = Contribution::query()
                    ->where('member_id', $member->id)
                    ->where('period', $period)
                    ->first();

                $due = (float) $member->monthly_contribution_amount;
                $collected = (float) ($contribution?->amount_collected ?? 0);
                $status = $contribution?->status ?? 'missing';
                $collectionStatus = $contribution?->collection_status ?? ContributionCollectionStatus::PENDING;

                fputcsv($handle, [
                    $member->member_number,
                    $member->name,
                    number_format($due, 2, '.', ''),
                    number_format($collected, 2, '.', ''),
                    $status,
                    $collectionStatus,
                    number_format((float) ($contribution?->late_fee_amount ?? 0), 2, '.', ''),
                    number_format($member->getCashBalance(), 2, '.', ''),
                ]);
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
