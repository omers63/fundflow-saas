<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\LoanInstallment;
use App\Services\Loans\LoanDelinquencyService;
use App\Services\Loans\LoanEmiCollectionCatalogService;
use App\Support\InstallmentCollectionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Open-cycle collection arrears snapshot and catalog consistency checks
 * (contributions + EMI lists scoped to labelled cycles before the anchor period).
 */
final class CollectionArrearsCatalogService
{
    public function __construct(
        protected ContributionCycleService $cycles,
        protected LoanDelinquencyService $delinquency,
        protected LoanEmiCollectionCatalogService $emiCatalog,
    ) {}

    /**
     * @return array{
     *     month: int,
     *     year: int,
     *     period_label: string,
     *     contribution_arrears_periods: int,
     *     contribution_arrears_members: int,
     *     emi_arrears_installments: int,
     *     emi_arrears_members: int,
     *     total_items: int,
     * }
     */
    public function openCycleSnapshot(): array
    {
        [$month, $year] = $this->cycles->currentOpenPeriod();

        $contributionArrearsPeriods = $this->delinquency->countContributionArrearsPeriods(
            memberId: null,
            throughMonth: $month,
            throughYear: $year,
            live: true,
        );

        $contributionArrearsMembers = $this->delinquency
            ->contributionArrearsTableRecords(null, $month, $year, true)
            ->pluck('member_id')
            ->unique()
            ->count();

        $emiArrearsInstallments = $this->emiCatalog->emiArrearsInstallmentCount($month, $year, true);
        $emiArrearsMembers = $this->emiCatalog->emiArrearsMemberCount($month, $year, true);

        return [
            'month' => $month,
            'year' => $year,
            'period_label' => $this->cycles->periodLabel($month, $year),
            'contribution_arrears_periods' => $contributionArrearsPeriods,
            'contribution_arrears_members' => $contributionArrearsMembers,
            'emi_arrears_installments' => $emiArrearsInstallments,
            'emi_arrears_members' => $emiArrearsMembers,
            'total_items' => $contributionArrearsPeriods + $emiArrearsInstallments,
        ];
    }

    /**
     * @return array{
     *     period_label: string,
     *     snapshot: array<string, mixed>,
     *     issues: list<array<string, mixed>>,
     *     issue_count: int,
     * }
     */
    public function catalogConsistencyIssues(): array
    {
        [$month, $year] = $this->cycles->currentOpenPeriod();
        $snapshot = $this->openCycleSnapshot();
        $issues = [];

        $contributionScopedCount = $snapshot['contribution_arrears_periods'];
        $contributionTableCount = $this->delinquency
            ->contributionArrearsTableRecords(null, $month, $year, true)
            ->count();

        if ($contributionScopedCount !== $contributionTableCount) {
            $issues[] = [
                'type' => 'contribution_arrears_count_drift',
                'scoped_count' => $contributionScopedCount,
                'table_count' => $contributionTableCount,
            ];
        }

        $emiCatalogCount = $snapshot['emi_arrears_installments'];
        $emiCollectionCount = $this->emiCatalog
            ->emiArrearsInstallmentsForPeriod($month, $year, true)
            ->count();

        if ($emiCatalogCount !== $emiCollectionCount) {
            $issues[] = [
                'type' => 'emi_arrears_count_drift',
                'catalog_count' => $emiCatalogCount,
                'collection_count' => $emiCollectionCount,
            ];
        }

        $arrearsInstallmentIds = $this->emiCatalog
            ->emiArrearsInstallmentsForPeriod($month, $year, true)
            ->pluck('id');

        $toCollectInstallmentIds = $this->openCycleToCollectInstallmentIds($month, $year);

        $overlap = $arrearsInstallmentIds
            ->intersect($toCollectInstallmentIds)
            ->values()
            ->all();

        if ($overlap !== []) {
            $issues[] = [
                'type' => 'emi_collect_arrears_overlap',
                'installment_ids' => array_slice($overlap, 0, 20),
                'overlap_count' => count($overlap),
            ];
        }

        return [
            'period_label' => $snapshot['period_label'],
            'snapshot' => $snapshot,
            'issues' => $issues,
            'issue_count' => count($issues),
        ];
    }

    /**
     * @return Collection<int, int>
     */
    private function openCycleToCollectInstallmentIds(int $month, int $year): Collection
    {
        [$start, $end] = $this->cycles->cycleDueDateBounds($month, $year);

        return LoanInstallment::query()
            ->whereIn('status', ['pending', 'overdue'])
            ->where(function (Builder $query): void {
                $query->whereNull('collection_status')
                    ->orWhereIn('collection_status', InstallmentCollectionStatus::openCollectionStates());
            })
            ->whereBetween('due_date', [$start, $end])
            ->whereHas('loan', function (Builder $loan): void {
                $loan->whereIn('status', ['active', 'transferred']);
            })
            ->with(['loan.member'])
            ->get()
            ->filter(function (LoanInstallment $installment): bool {
                if ($installment->due_date === null) {
                    return false;
                }

                $member = $installment->loan?->member;

                if ($member === null) {
                    return false;
                }

                [$cycleMonth, $cycleYear] = $this->cycles->cyclePeriodForDueDate($installment->due_date);

                return ! Contribution::blocksLoanRepaymentForMemberPeriod($member, $cycleMonth, $cycleYear);
            })
            ->pluck('id');
    }
}
