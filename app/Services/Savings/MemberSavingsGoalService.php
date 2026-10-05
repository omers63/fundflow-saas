<?php

declare(strict_types=1);

namespace App\Services\Savings;

use App\Models\Tenant\Member;
use App\Models\Tenant\MemberSavingsGoal;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final class MemberSavingsGoalService
{
    /**
     * @return array{
     *     current_fund_balance: float,
     *     target_amount: float,
     *     progress_percent: float,
     *     remaining: float,
     *     monthly_contribution: float,
     *     projected_hit_date: ?string,
     *     months_to_goal: ?int,
     *     behind_schedule: bool
     * }
     */
    public function progress(MemberSavingsGoal $goal): array
    {
        $goal->loadMissing('member');
        $member = $goal->member;

        if ($member === null) {
            throw new InvalidArgumentException(__('Member is required for savings goal progress.'));
        }

        $balance = round($member->getFundBalance(), 2);
        $target = round((float) $goal->target_amount, 2);
        $remaining = max(0.0, round($target - $balance, 2));
        $percent = $target > 0 ? min(100.0, round(($balance / $target) * 100, 2)) : 100.0;
        $monthly = round((float) ($member->monthly_contribution_amount ?? 0), 2);

        $monthsToGoal = null;
        $projectedHit = null;

        if ($remaining <= 0) {
            $monthsToGoal = 0;
            $projectedHit = now()->toDateString();
        } elseif ($monthly > 0) {
            $monthsToGoal = (int) ceil($remaining / $monthly);
            $projectedHit = now()->addMonthsNoOverflow($monthsToGoal)->toDateString();
        }

        $behind = false;
        if ($goal->target_date !== null && $remaining > 0) {
            $targetDate = Carbon::parse($goal->target_date)->startOfDay();
            if ($projectedHit === null) {
                $behind = true;
            } else {
                $behind = Carbon::parse($projectedHit)->gt($targetDate);
            }
        }

        return [
            'current_fund_balance' => $balance,
            'target_amount' => $target,
            'progress_percent' => $percent,
            'remaining' => $remaining,
            'monthly_contribution' => $monthly,
            'projected_hit_date' => $projectedHit,
            'months_to_goal' => $monthsToGoal,
            'behind_schedule' => $behind,
        ];
    }

    /**
     * @param  array{title: string, target_amount: float|int|string, target_date?: ?string, notes?: ?string}  $data
     */
    public function create(Member $member, array $data): MemberSavingsGoal
    {
        $goal = MemberSavingsGoal::query()->create([
            'member_id' => $member->id,
            'title' => $data['title'],
            'target_amount' => $data['target_amount'],
            'target_date' => $data['target_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => MemberSavingsGoal::STATUS_ACTIVE,
        ]);

        $this->syncAchieved($goal->fresh());

        return $goal->fresh();
    }

    public function syncAchieved(MemberSavingsGoal $goal): MemberSavingsGoal
    {
        if (! $goal->isActive()) {
            return $goal;
        }

        $progress = $this->progress($goal);

        if ($progress['remaining'] <= 0) {
            $goal->forceFill([
                'status' => MemberSavingsGoal::STATUS_ACHIEVED,
                'achieved_at' => now(),
            ])->save();
        }

        return $goal->fresh();
    }
}
