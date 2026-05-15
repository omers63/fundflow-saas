<?php

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ContributionService
{
    public function __construct(
        public AccountingService $accounting,
    ) {}

    /**
     * Get the configured cycle start day (default 6th of month).
     */
    public function getCycleStartDay(): int
    {
        return (int) Setting::get('contribution', 'cycle_start_day', 6);
    }

    /**
     * Determine the contribution cycle period for a given date.
     * A cycle starts on the configured day and ends the day before the next cycle.
     */
    public function getCyclePeriod(?Carbon $date = null): Carbon
    {
        $date ??= now();
        $startDay = $this->getCycleStartDay();

        if ($date->day >= $startDay) {
            return $date->copy()->startOfMonth();
        }

        return $date->copy()->subMonth()->startOfMonth();
    }

    /**
     * Get the cycle date range for a given period.
     *
     * @return array{start: Carbon, end: Carbon}
     */
    public function getCycleDateRange(Carbon $period): array
    {
        $startDay = $this->getCycleStartDay();

        $start = $period->copy()->day($startDay)->startOfDay();
        $end = $period->copy()->addMonth()->day($startDay)->subDay()->endOfDay();

        return ['start' => $start, 'end' => $end];
    }

    /**
     * Record a pending contribution for a member for a given period.
     */
    public function recordContribution(Member $member, string $period, ?float $amount = null): Contribution
    {
        return Contribution::create([
            'member_id' => $member->id,
            'period' => $period,
            'amount' => $amount ?? $member->monthly_contribution_amount,
            'status' => 'pending',
        ]);
    }

    /**
     * Run the contribution cycle collection for a given period.
     *
     * For each active member, transfers the elected fund amount from the
     * member's cash account to their fund account, and mirrors to master fund.
     * Only one contribution or repayment is allowed per member per cycle.
     *
     * Steps per member:
     * 1. Debit member's cash account
     * 2. Credit member's fund account
     * 3. Mirror (credit) master fund account
     */
    public function postContribution(Contribution $contribution): void
    {
        DB::transaction(function () use ($contribution) {
            $member = $contribution->member;
            $masterFund = Account::masterFund();
            $memberCash = $member->cashAccount;
            $memberFund = $member->fundAccount;

            $amount = (float) $contribution->amount;
            $description = "Contribution for {$contribution->period->format('M Y')}";

            $this->accounting->transfer($memberCash, $memberFund, $amount, $description, $contribution);

            $this->accounting->mirror($masterFund, $amount, "Fund growth: {$description}", $contribution);

            $contribution->update([
                'status' => 'posted',
                'posted_at' => now(),
            ]);
        });
    }

    /**
     * Generate pending contributions for all active members for a given period.
     * Only one contribution per member per cycle is allowed.
     */
    public function generateMonthlyContributions(string $period): int
    {
        $count = 0;

        Member::active()->each(function (Member $member) use ($period, &$count) {
            $exists = Contribution::where('member_id', $member->id)
                ->where('period', $period)
                ->exists();

            if (! $exists && $member->monthly_contribution_amount > 0) {
                $this->recordContribution($member, $period);
                $count++;
            }
        });

        return $count;
    }
}
