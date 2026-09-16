<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Services\Loans\LoanDelinquencyService;
use App\Services\MemberLatePaymentHistoryEvaluator;
use App\Support\RiskSettings;
use App\Support\TenantRuntimeCache;

final class MemberRiskScoreService
{
    public function __construct(
        private readonly MemberLatePaymentHistoryEvaluator $lateHistory,
        private readonly LoanDelinquencyService $delinquency,
    ) {}

    /**
     * @return array{
     *     score: int,
     *     band: string,
     *     factors: list<array{code: string, label: string, points: int}>,
     *     member_id: int
     * }
     */
    public function score(Member $member): array
    {
        $ttl = RiskSettings::cacheTtlSeconds();

        return TenantRuntimeCache::remember(
            $this->cacheKey($member->id),
            $ttl,
            fn (): array => $this->compute($member),
        );
    }

    public function forget(int $memberId): void
    {
        TenantRuntimeCache::forget($this->cacheKey($memberId));
    }

    /**
     * @return list<array{member: Member, score: array{score: int, band: string, factors: list<array{code: string, label: string, points: int}>, member_id: int}}>
     */
    public function watchlist(string $minBand = 'high'): array
    {
        $bands = match ($minBand) {
            'critical' => ['critical'],
            'medium' => ['medium', 'high', 'critical'],
            default => ['high', 'critical'],
        };

        $rows = [];

        Member::query()
            ->where('status', 'active')
            ->orderBy('id')
            ->each(function (Member $member) use (&$rows, $bands): void {
                $score = $this->score($member);

                if (in_array($score['band'], $bands, true)) {
                    $rows[] = ['member' => $member, 'score' => $score];
                }
            });

        usort($rows, fn (array $a, array $b): int => $b['score']['score'] <=> $a['score']['score']);

        return $rows;
    }

    public function bandColor(string $band): string
    {
        return match ($band) {
            'critical' => 'danger',
            'high' => 'warning',
            'medium' => 'info',
            default => 'success',
        };
    }

    /**
     * @return array{
     *     score: int,
     *     band: string,
     *     factors: list<array{code: string, label: string, points: int}>,
     *     member_id: int
     * }
     */
    private function compute(Member $member): array
    {
        $factors = [];
        $points = 0;

        $history = $this->lateHistory->evaluate($member);
        $lateTotal = (int) ($history['rolling_total'] ?? 0);
        $lateStreak = (int) ($history['trailing_consecutive'] ?? 0);

        if ($lateTotal > 0) {
            $add = min(30, $lateTotal * 8);
            $factors[] = [
                'code' => 'late_history',
                'label' => __('Late settlements in lookback (:count)', ['count' => $lateTotal]),
                'points' => $add,
            ];
            $points += $add;
        }

        if ($lateStreak >= 2) {
            $add = min(20, $lateStreak * 6);
            $factors[] = [
                'code' => 'late_streak',
                'label' => __('Consecutive late periods (:count)', ['count' => $lateStreak]),
                'points' => $add,
            ];
            $points += $add;
        }

        if ($this->delinquency->isDelinquent($member)) {
            $factors[] = [
                'code' => 'delinquent',
                'label' => __('Currently policy-delinquent'),
                'points' => 25,
            ];
            $points += 25;
        }

        $requiredContribution = (float) $member->monthly_contribution_amount;
        $cash = (float) $member->getCashBalance();
        if ($requiredContribution > 0 && $cash < $requiredContribution) {
            $add = 15;
            $factors[] = [
                'code' => 'cash_cover',
                'label' => __('Cash below next contribution'),
                'points' => $add,
            ];
            $points += $add;
        }

        $activeLoans = (int) Loan::query()
            ->where('member_id', $member->id)
            ->whereIn('status', ['active', 'partially_disbursed', 'approved'])
            ->count();

        if ($activeLoans > 0) {
            $add = min(20, $activeLoans * 10);
            $factors[] = [
                'code' => 'active_loans',
                'label' => __('Active or approved loans (:count)', ['count' => $activeLoans]),
                'points' => $add,
            ];
            $points += $add;
        }

        $guaranteed = (float) Loan::query()
            ->where('guarantor_member_id', $member->id)
            ->whereIn('status', ['active', 'partially_disbursed', 'approved', 'transferred'])
            ->sum('amount_approved');

        if ($guaranteed > 0) {
            $add = min(20, (int) floor($guaranteed / 5000) * 5 + 5);
            $factors[] = [
                'code' => 'guarantor_exposure',
                'label' => __('Guarantor exposure :amount', ['amount' => number_format($guaranteed, 2)]),
                'points' => $add,
            ];
            $points += $add;
        }

        $dependents = (int) Member::query()
            ->where('parent_member_id', $member->id)
            ->where('status', 'active')
            ->count();

        if ($dependents >= 2) {
            $add = min(10, $dependents * 3);
            $factors[] = [
                'code' => 'household_size',
                'label' => __('Household dependents (:count)', ['count' => $dependents]),
                'points' => $add,
            ];
            $points += $add;
        }

        $score = max(0, min(100, $points));
        $band = $this->bandForScore($score);

        return [
            'score' => $score,
            'band' => $band,
            'factors' => $factors,
            'member_id' => $member->id,
        ];
    }

    private function bandForScore(int $score): string
    {
        if ($score >= RiskSettings::criticalScoreThreshold()) {
            return 'critical';
        }

        if ($score >= RiskSettings::highScoreThreshold()) {
            return 'high';
        }

        if ($score >= 30) {
            return 'medium';
        }

        return 'low';
    }

    private function cacheKey(int $memberId): string
    {
        return 'member_risk_score:'.$memberId;
    }
}
