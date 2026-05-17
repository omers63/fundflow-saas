<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Services\Loans\LateFeeService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ContributionService
{
    public function __construct(
        public AccountingService $accounting,
        public ContributionCycleService $cycles,
        public LateFeeService $lateFees,
    ) {}

    public function getCycleStartDay(): int
    {
        return $this->cycles->cycleStartDay();
    }

    public function getCyclePeriod(?Carbon $date = null): Carbon
    {
        [$month, $year] = $this->cycles->currentOpenPeriod();

        return Carbon::create($year, $month, 1)->startOfMonth();
    }

    /**
     * @return array{start: Carbon, end: Carbon}
     */
    public function getCycleDateRange(Carbon $period): array
    {
        $month = (int) $period->month;
        $year = (int) $period->year;

        return [
            'start' => $this->cycles->cycleStartAt($month, $year),
            'end' => $this->cycles->cycleDueEndAt($month, $year),
        ];
    }

    public function recordContribution(Member $member, string $period, ?float $amount = null): Contribution
    {
        return Contribution::create([
            'member_id' => $member->id,
            'period' => $period,
            'amount' => $amount ?? $member->monthly_contribution_amount,
            'status' => 'pending',
            'payment_method' => Contribution::PAYMENT_METHOD_ADMIN,
        ]);
    }

    public function postContribution(Contribution $contribution): void
    {
        if ($contribution->status === 'posted') {
            return;
        }

        $member = $contribution->member;
        $memberCash = $member->cashAccount;
        $memberFund = $member->fundAccount;

        if ($memberCash === null || $memberFund === null) {
            throw new InvalidArgumentException(__('Member accounts are not configured.'));
        }

        $amount = (float) $contribution->amount;
        $lateFee = (float) ($contribution->late_fee_amount ?? 0);
        $totalDebit = $amount + $lateFee;

        if ($contribution->payment_method === Contribution::PAYMENT_METHOD_CASH_ACCOUNT) {
            if ((float) $memberCash->balance < $totalDebit) {
                $contribution->update(['status' => 'failed']);

                throw new InvalidArgumentException(__('Insufficient cash. Required: :amount', [
                    'amount' => number_format($totalDebit, 2),
                ]));
            }
        }

        DB::transaction(function () use ($contribution): void {
            $this->accounting->postContribution($contribution);

            $contribution->update([
                'status' => 'posted',
                'posted_at' => now(),
                'paid_at' => $contribution->paid_at ?? now(),
            ]);
        });
    }

    /**
     * Apply (create + post) a cycle contribution for one member.
     *
     * @param  array<string, mixed>  $results
     */
    public function applyForPeriod(Member $member, int $month, int $year, array &$results = []): string
    {
        if (Contribution::query()->where('member_id', $member->id)->forPeriod($month, $year)->exists()) {
            $results['skipped'][] = $member;

            return 'already_contributed';
        }

        if ($member->isExemptFromContributions()) {
            $results['skipped'][] = $member;

            return 'exempt';
        }

        $amount = (float) $member->monthly_contribution_amount;

        if ($amount <= 0) {
            $results['skipped'][] = $member;

            return 'exempt';
        }

        $deadline = $this->cycles->deadline($month, $year);
        $days = $this->lateFees->daysPastDue($deadline, now());
        $lateFee = $this->lateFees->contributionLateFeeForDays($days);
        $required = $amount + $lateFee;

        $member->unsetRelation('accounts');
        $cashBalance = $member->getCashBalance();

        if ($cashBalance < $required) {
            $results['insufficient'][] = [
                'member' => $member,
                'balance' => $cashBalance,
                'required' => $required,
            ];

            return 'insufficient';
        }

        try {
            DB::transaction(function () use ($member, $month, $year, $amount, $lateFee, $days): void {
                $contribution = Contribution::create([
                    'member_id' => $member->id,
                    'period' => Contribution::periodDate($month, $year),
                    'amount' => $amount,
                    'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
                    'is_late' => $days >= 1,
                    'late_fee_amount' => $lateFee > 0 ? $lateFee : null,
                    'paid_at' => now(),
                    'status' => 'pending',
                ]);

                $this->postContribution($contribution);
            });
        } catch (UniqueConstraintViolationException|ValidationException) {
            $results['skipped'][] = $member;

            return 'already_contributed';
        } catch (InvalidArgumentException) {
            $results['insufficient'][] = [
                'member' => $member,
                'balance' => $member->getCashBalance(),
                'required' => $required,
            ];

            return 'insufficient';
        }

        $results['applied'][] = $member;

        return 'applied';
    }

    /**
     * @return array{applied: Collection, insufficient: Collection, skipped: Collection}
     */
    public function applyContributionsForPeriod(int $month, int $year): array
    {
        $results = [
            'applied' => collect(),
            'insufficient' => collect(),
            'skipped' => collect(),
        ];

        Member::active()->with('user')->each(function (Member $member) use ($month, $year, &$results): void {
            $this->applyForPeriod($member, $month, $year, $results);
        });

        return $results;
    }

    public function generateMonthlyContributions(int|string|null $month = null, ?int $year = null): int
    {
        if (is_string($month)) {
            $date = Carbon::parse($month);
            $month = (int) $date->month;
            $year = (int) $date->year;
        }

        if ($month === null || $year === null) {
            [$month, $year] = $this->cycles->currentOpenPeriod();
        }

        $period = Contribution::periodDate($month, $year);
        $count = 0;

        Member::active()->each(function (Member $member) use ($period, &$count): void {
            $exists = Contribution::where('member_id', $member->id)
                ->where('period', $period)
                ->exists();

            if (! $exists && (float) $member->monthly_contribution_amount > 0 && ! $member->isExemptFromContributions()) {
                $this->recordContribution($member, $period);
                $count++;
            }
        });

        return $count;
    }

    public function periodLabel(int $month, int $year): string
    {
        return $this->cycles->periodLabel($month, $year);
    }
}
