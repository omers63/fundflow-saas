<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Services\ContributionCycleService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Single source of truth for contribution exemption during loan grace and EMI phases.
 *
 * Decisions D1-D16 (proposed defaults, confirmed 2026-07-11).
 */
final class ContributionExemptionPolicy
{
    public function __construct(
        protected ContributionCycleService $cycles,
    ) {}

    /**
     * @return list<array{0: int, 1: int}> labelled cycle (month, year) pairs
     */
    public function graceCycleLabels(Loan $loan): array
    {
        $graceCycles = $this->graceCyclesCount($loan);

        if ($graceCycles <= 0 || $loan->disbursed_at === null) {
            return [];
        }

        if ((string) $loan->status === 'transferred') {
            return [];
        }

        $member = $loan->relationLoaded('member')
            ? $loan->member
            : Member::query()->find($loan->member_id);

        if ($member === null) {
            return [];
        }

        $disbursedAt = Carbon::parse((string) $loan->disbursed_at);
        $exemption = Loan::finalizeExemptionForDisbursement(
            $member,
            Loan::computeExemptionAndFirstRepayment($disbursedAt, $graceCycles),
            $disbursedAt,
        );

        if ($exemption['exempted_month'] === null || $exemption['exempted_year'] === null) {
            return [];
        }

        $labels = [];
        $cursor = Carbon::create((int) $exemption['exempted_year'], (int) $exemption['exempted_month'], 1)
            ->subMonthsNoOverflow($graceCycles - 1);

        for ($i = 0; $i < $graceCycles; $i++) {
            $period = $cursor->copy()->addMonthsNoOverflow($i);
            $labels[] = [(int) $period->month, (int) $period->year];
        }

        return $labels;
    }

    public function isLoanInGraceCycle(Loan $loan, int $month, int $year): bool
    {
        foreach ($this->graceCycleLabels($loan) as [$graceMonth, $graceYear]) {
            if ($graceMonth === $month && $graceYear === $year) {
                return true;
            }
        }

        return false;
    }

    public function memberIsInGraceCycle(Member $member, int $month, int $year): bool
    {
        return $this->loansForMember($member)
            ->contains(fn (Loan $loan): bool => $this->isLoanInGraceCycle($loan, $month, $year));
    }

    /**
     * @return array{month: int, year: int}|null
     */
    public function resolvedFirstRepayment(Loan $loan): ?array
    {
        if ($loan->disbursed_at === null) {
            return null;
        }

        if ($loan->first_repayment_month !== null && $loan->first_repayment_year !== null) {
            return [
                'month' => (int) $loan->first_repayment_month,
                'year' => (int) $loan->first_repayment_year,
            ];
        }

        $graceCycles = $this->graceCyclesCount($loan);
        $disbursedAt = Carbon::parse((string) $loan->disbursed_at);

        $member = $loan->relationLoaded('member')
            ? $loan->member
            : Member::query()->find($loan->member_id);

        $exemption = Loan::computeExemptionAndFirstRepayment($disbursedAt, $graceCycles);

        if ($member !== null) {
            $exemption = Loan::finalizeExemptionForDisbursement($member, $exemption, $disbursedAt);
        }

        return [
            'month' => (int) $exemption['first_repayment_month'],
            'year' => (int) $exemption['first_repayment_year'],
        ];
    }

    public function emiStartAt(Loan $loan): ?Carbon
    {
        $first = $this->resolvedFirstRepayment($loan);

        if ($first === null) {
            return null;
        }

        return $this->cycles->cycleStartAt($first['month'], $first['year']);
    }

    public function repaymentEndedAt(Loan $loan): ?Carbon
    {
        $end = $loan->settled_at ?? $loan->completed_at;

        return $end !== null ? Carbon::parse((string) $end)->startOfDay() : null;
    }

    public function isLoanInEmiRepaymentPhase(Loan $loan, int $month, int $year): bool
    {
        if ($loan->disbursed_at === null) {
            return false;
        }

        if (! in_array((string) $loan->status, ['active', 'transferred', 'completed', 'early_settled'], true)) {
            return false;
        }

        $first = $this->resolvedFirstRepayment($loan);

        if ($first === null) {
            return false;
        }

        return $this->cycles->loanRepaymentOverlapsContributionCycle(
            $loan->disbursed_at,
            $loan->settled_at,
            $loan->completed_at,
            (string) $loan->status,
            $month,
            $year,
            $first['month'],
            $first['year'],
        );
    }

    public function memberIsInEmiRepaymentPhase(Member $member, int $month, int $year): bool
    {
        return $this->loansForMember($member)
            ->contains(fn (Loan $loan): bool => $this->isLoanInEmiRepaymentPhase($loan, $month, $year));
    }

    public function isContributionExemptForCycle(Member $member, int $month, int $year): bool
    {
        if ($member->hasPartiallyDisbursedLoan()) {
            return true;
        }

        if ($this->memberIsInGraceCycle($member, $month, $year)) {
            return true;
        }

        return $this->memberIsInEmiRepaymentPhase($member, $month, $year);
    }

    public function isContributionExemptNow(Member $member): bool
    {
        [$month, $year] = $this->cycles->currentOpenPeriod();

        return $this->isContributionExemptForCycle($member, $month, $year);
    }

    public function isVoluntaryGraceContribution(Member $member, int $month, int $year): bool
    {
        return $this->memberIsInGraceCycle($member, $month, $year);
    }

    protected function graceCyclesCount(Loan $loan): int
    {
        if ($loan->grace_cycles !== null) {
            return max(0, (int) $loan->grace_cycles);
        }

        return $loan->has_grace_cycle ? 1 : 0;
    }

    /**
     * @return Collection<int, Loan>
     */
    protected function loansForMember(Member $member): Collection
    {
        if ($member->relationLoaded('loans')) {
            return $member->loans;
        }

        return Loan::query()
            ->where('member_id', $member->id)
            ->whereNotNull('disbursed_at')
            ->get();
    }
}
