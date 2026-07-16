<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Support\BusinessDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Loan queue priority scoring (admin portal spec §17.6).
 *
 * priority_score = base_type_score + tenure_bonus + days_in_queue_bonus + standing_bonus
 */
final class LoanQueuePriorityScoreService
{
    public function calculate(Loan $loan): int
    {
        $member = $loan->relationLoaded('member') ? $loan->member : $loan->member()->first();

        $baseType = $loan->is_emergency ? 100 : 50;
        $tenureBonus = min($this->memberTenureYears($member) * 2, 20);
        $queueBonus = min($this->daysWaiting($loan) * 1.5, 30);
        $standingBonus = $this->hasCleanStanding($member) ? 10 : 0;

        return (int) round($baseType + $tenureBonus + $queueBonus + $standingBonus);
    }

    public function applySort(Builder $query, string $direction = 'desc'): Builder
    {
        if (! $this->queryHasMembersJoin($query)) {
            $query->leftJoin('members', 'members.id', '=', 'loans.member_id');
        }

        if ($query->getQuery()->columns === null || $query->getQuery()->columns === ['*']) {
            $query->select('loans.*');
        }

        $dir = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        return $query->orderByRaw($this->scoreSqlExpression().' '.$dir);
    }

    public function scoreSqlExpression(): string
    {
        $asOf = BusinessDay::today()->toDateString();
        $quoted = DB::connection()->getPdo()->quote($asOf);

        return match (DB::connection()->getDriverName()) {
            'sqlite' => "(
                CASE WHEN loans.is_emergency = 1 THEN 100 ELSE 50 END
                + MIN(MAX((julianday({$quoted}) - julianday(members.joined_at)) / 365.25 * 2, 0), 20)
                + MIN(MAX((julianday({$quoted}) - julianday(COALESCE(loans.applied_at, loans.created_at))) * 1.5, 0), 30)
                + CASE WHEN members.status = 'active' THEN 10 ELSE 0 END
            )",
            default => "(
                IF(loans.is_emergency, 100, 50)
                + LEAST(GREATEST(TIMESTAMPDIFF(YEAR, members.joined_at, {$quoted}), 0) * 2, 20)
                + LEAST(GREATEST(DATEDIFF({$quoted}, COALESCE(loans.applied_at, loans.created_at)), 0) * 1.5, 30)
                + IF(members.status = 'active', 10, 0)
            )",
        };
    }

    protected function memberTenureYears(?Member $member): int
    {
        if ($member?->joined_at === null) {
            return 0;
        }

        return max(0, (int) $member->joined_at->diffInYears(BusinessDay::now()));
    }

    protected function daysWaiting(Loan $loan): int
    {
        $appliedAt = $loan->applied_at ?? $loan->created_at;

        if ($appliedAt === null) {
            return 0;
        }

        return max(0, (int) $appliedAt->diffInDays(BusinessDay::now()));
    }

    protected function hasCleanStanding(?Member $member): bool
    {
        if ($member === null) {
            return false;
        }

        return $member->status === 'active';
    }

    protected function queryHasMembersJoin(Builder $query): bool
    {
        $joins = $query->getQuery()->joins ?? [];

        foreach ($joins as $join) {
            if ($join->table === 'members') {
                return true;
            }
        }

        return false;
    }
}
