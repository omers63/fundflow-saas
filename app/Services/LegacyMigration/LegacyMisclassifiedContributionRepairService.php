<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Member;
use App\Services\ContributionService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Replays legacy imported payments chronologically and converts contributions
 * that should have been loan repayments (e.g. monthly amount below tier EMI at cycle start).
 */
final class LegacyMisclassifiedContributionRepairService
{
    public function __construct(
        private readonly LegacyPaymentLoanAllocator $loanAllocator,
        private readonly LegacyPaymentImportService $paymentImport,
        private readonly LegacyLoanRepaymentWindowResolver $repaymentWindowResolver,
        private readonly LegacyImportedLoanScheduleSyncService $scheduleSync,
        private readonly ContributionService $contributions,
    ) {}

    /**
     * @return array{
     *     members_processed: int,
     *     contributions_removed: int,
     *     repayments_posted: int,
     *     contributions_adjusted: int,
     *     loans_synced: int,
     *     installments_marked: int
     * }
     */
    public function repairMember(Member $member): array
    {
        @set_time_limit(0);

        $stats = [
            'members_processed' => 1,
            'contributions_removed' => 0,
            'repayments_posted' => 0,
            'contributions_adjusted' => 0,
            'loans_synced' => 0,
            'installments_marked' => 0,
        ];

        $cumulativeRepaidByLoanKey = [];
        $affectedLoanIds = [];

        $events = $this->paymentTimeline($member);

        ContributionService::withoutPostedNotifications(function () use ($member, $events, &$cumulativeRepaidByLoanKey, &$affectedLoanIds, &$stats): void {
            ContributionService::withoutLiveCollectionGuards(function () use ($member, $events, &$cumulativeRepaidByLoanKey, &$affectedLoanIds, &$stats): void {
                foreach ($events as $event) {
                    if ($event['type'] === 'repayment') {
                        /** @var LoanRepayment $repayment */
                        $repayment = $event['record'];
                        $loan = $repayment->loan;

                        if ($loan !== null) {
                            $this->repaymentWindowResolver->recordRepayment(
                                $loan,
                                $member,
                                (float) $repayment->amount,
                                $cumulativeRepaidByLoanKey,
                            );
                        }

                        continue;
                    }

                    /** @var Contribution $contribution */
                    $contribution = $event['record'];
                    $postedAt = Carbon::parse((string) ($contribution->posted_at ?? $contribution->period));
                    $amount = (float) $contribution->amount;

                    $allocation = $this->loanAllocator->allocate(
                        $member,
                        $amount,
                        $postedAt,
                        $cumulativeRepaidByLoanKey,
                    );

                    if ($allocation['repayment_amount'] <= 0.00001 || $allocation['loan'] === null) {
                        continue;
                    }

                    $repaymentAmount = $allocation['repayment_amount'];
                    $contributionRemainder = $allocation['contribution_amount'];
                    $notes = $contribution->notes ?: __('Legacy migration contribution repair');

                    DB::transaction(function () use ($member, $contribution, $allocation, $repaymentAmount, $contributionRemainder, $postedAt, $notes, &$affectedLoanIds, &$cumulativeRepaidByLoanKey, &$stats): void {
                        $this->contributions->reverseImportedContributionForMigrationRepair($contribution);
                        $stats['contributions_removed']++;

                        $posted = $this->paymentImport->postAllocatedLoanRepaymentForRepair(
                            $allocation['loan'],
                            $repaymentAmount,
                            $postedAt,
                            $notes,
                            $affectedLoanIds,
                            $cumulativeRepaidByLoanKey,
                        );

                        if ($posted) {
                            $stats['repayments_posted']++;
                        }

                        $remainingAmount = $contributionRemainder;

                        while ($remainingAmount > 0.00001) {
                            $followUp = $this->loanAllocator->allocate(
                                $member,
                                $remainingAmount,
                                $postedAt,
                                $cumulativeRepaidByLoanKey,
                            );

                            if ($followUp['repayment_amount'] <= 0.00001 || $followUp['loan'] === null) {
                                break;
                            }

                            $followUpPosted = $this->paymentImport->postAllocatedLoanRepaymentForRepair(
                                $followUp['loan'],
                                $followUp['repayment_amount'],
                                $postedAt,
                                $notes,
                                $affectedLoanIds,
                                $cumulativeRepaidByLoanKey,
                            );

                            if ($followUpPosted) {
                                $stats['repayments_posted']++;
                            }

                            $remainingAmount = $followUp['contribution_amount'];
                        }

                        if ($remainingAmount > 0.00001) {
                            [$month, $year] = [
                                (int) $postedAt->month,
                                (int) $postedAt->year,
                            ];

                            $this->paymentImport->postLegacyContributionForRepair(
                                $member,
                                $month,
                                $year,
                                $remainingAmount,
                                $postedAt,
                                $notes.' ['.__('Repaired — contribution remainder after loan allocation').']',
                            );
                            $stats['contributions_adjusted']++;
                        }
                    });
                }
            });
        });

        if ($affectedLoanIds !== []) {
            $sync = $this->scheduleSync->syncLoans($affectedLoanIds);
            $stats['loans_synced'] = $sync['loans'];
            $stats['installments_marked'] = $sync['installments'];
        }

        return $stats;
    }

    /**
     * @return list<array{type: string, at: Carbon, record: Contribution|LoanRepayment}>
     */
    private function paymentTimeline(Member $member): array
    {
        $events = [];

        foreach (Contribution::query()
            ->where('member_id', $member->id)
            ->where('status', 'posted')
            ->get() as $contribution) {
            $events[] = [
                'type' => 'contribution',
                'at' => Carbon::parse((string) ($contribution->posted_at ?? $contribution->period)),
                'record' => $contribution,
            ];
        }

        foreach (LoanRepayment::query()
            ->whereHas('loan', fn ($query) => $query->where('member_id', $member->id))
            ->with('loan')
            ->get() as $repayment) {
            $events[] = [
                'type' => 'repayment',
                'at' => Carbon::parse((string) $repayment->paid_at),
                'record' => $repayment,
            ];
        }

        usort($events, function (array $left, array $right): int {
            $comparison = $left['at']->timestamp <=> $right['at']->timestamp;

            if ($comparison !== 0) {
                return $comparison;
            }

            return $left['type'] === 'repayment' ? -1 : 1;
        });

        return $events;
    }

    /**
     * @return array{
     *     members_processed: int,
     *     contributions_removed: int,
     *     repayments_posted: int,
     *     contributions_adjusted: int,
     *     loans_synced: int,
     *     installments_marked: int
     * }
     */
    public function repairMembers(iterable $members): array
    {
        $totals = [
            'members_processed' => 0,
            'contributions_removed' => 0,
            'repayments_posted' => 0,
            'contributions_adjusted' => 0,
            'loans_synced' => 0,
            'installments_marked' => 0,
        ];

        foreach (Collection::make($members) as $member) {
            if (! $member instanceof Member) {
                continue;
            }

            $result = $this->repairMember($member);
            $totals['members_processed']++;
            $totals['contributions_removed'] += $result['contributions_removed'];
            $totals['repayments_posted'] += $result['repayments_posted'];
            $totals['contributions_adjusted'] += $result['contributions_adjusted'];
            $totals['loans_synced'] += $result['loans_synced'];
            $totals['installments_marked'] += $result['installments_marked'];
        }

        return $totals;
    }
}
