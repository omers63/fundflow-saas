<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\DependentCashAllocation;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Notifications\Tenant\ContributionDueNotification;
use App\Services\Loans\LateFeeService;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Contribution cycle windows, dependent allocations, and bulk apply orchestration.
 */
class ContributionCycleService
{
    private const CONTRIBUTION_CYCLE_LOOKBACK_MONTHS = 24;

    public function __construct(
        protected AccountingService $accounting,
        protected LateFeeService $lateFees,
    ) {}

    public function cycleStartDay(): int
    {
        return Setting::contributionCycleStartDay();
    }

    public function cycleStartAt(int $month, int $year): Carbon
    {
        $day = $this->cycleStartDay();
        $last = (int) Carbon::create($year, $month, 1)->endOfMonth()->day;
        $d = min($day, $last);

        return Carbon::create($year, $month, $d)->startOfDay();
    }

    public function cycleDueEndAt(int $month, int $year): Carbon
    {
        $start = $this->cycleStartAt($month, $year);
        $nextMonth = $start->copy()->addMonthNoOverflow();
        $nextStart = $this->cycleStartAt((int) $nextMonth->month, (int) $nextMonth->year);

        return $nextStart->copy()->subDay()->endOfDay();
    }

    public function deadline(int $month, int $year): Carbon
    {
        return $this->cycleDueEndAt($month, $year);
    }

    public function isLate(int $month, int $year): bool
    {
        return BusinessDay::now()->greaterThan($this->deadline($month, $year));
    }

    public function periodLabel(int $month, int $year): string
    {
        return Carbon::create($year, $month, 1)
            ->locale(app()->getLocale())
            ->translatedFormat('F Y');
    }

    public function cycleWindowDescription(int $month, int $year): string
    {
        $start = $this->cycleStartAt($month, $year);
        $dueEnd = $this->cycleDueEndAt($month, $year);

        return __(':start – :due (due end of :due_end)', [
            'start' => $start->copy()->locale(app()->getLocale())->translatedFormat('j M Y'),
            'due' => $dueEnd->copy()->locale(app()->getLocale())->translatedFormat('j M Y'),
            'due_end' => $dueEnd->copy()->locale(app()->getLocale())->translatedFormat('j M Y'),
        ]);
    }

    /**
     * Whether a due date falls in the labelled cycle window (inclusive: start day through day before next cycle start).
     */
    public function dueDateFallsInCycle(Carbon|string $dueDate, int $month, int $year): bool
    {
        $due = Carbon::parse($dueDate)->startOfDay();

        return $due->greaterThanOrEqualTo($this->cycleStartAt($month, $year))
            && $due->lessThanOrEqualTo($this->cycleDueEndAt($month, $year)->startOfDay());
    }

    /**
     * Resolve the contribution/EMI cycle label (month, year) that contains the given due date.
     *
     * @return array{0: int, 1: int}
     */
    public function cyclePeriodForDueDate(Carbon|string $dueDate): array
    {
        $due = Carbon::parse($dueDate)->startOfDay();
        $cursor = $due->copy()->startOfMonth();

        for ($i = 0; $i < 24; $i++) {
            $m = (int) $cursor->month;
            $y = (int) $cursor->year;

            if ($this->dueDateFallsInCycle($due, $m, $y)) {
                return [$m, $y];
            }

            $cursor->subMonthNoOverflow();
        }

        return [(int) $due->month, (int) $due->year];
    }

    public function isDueDateOnOrBeforeCycleEnd(Carbon|string $dueDate, int $throughMonth, int $throughYear): bool
    {
        return Carbon::parse($dueDate)->startOfDay()->lessThanOrEqualTo(
            $this->cycleDueEndAt($throughMonth, $throughYear)->startOfDay(),
        );
    }

    /**
     * @return array{0: string, 1: string} cycle start and end dates (Y-m-d) for SQL due_date filters
     */
    public function cycleDueDateBounds(int $month, int $year): array
    {
        return [
            $this->cycleStartAt($month, $year)->toDateString(),
            $this->cycleDueEndAt($month, $year)->toDateString(),
        ];
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function currentOpenPeriod(): array
    {
        $now = BusinessDay::now();
        $cursor = $now->copy()->startOfMonth();

        for ($i = 0; $i < 15; $i++) {
            $m = (int) $cursor->month;
            $y = (int) $cursor->year;
            $start = $this->cycleStartAt($m, $y);
            $dueEnd = $this->cycleDueEndAt($m, $y);

            if ($now->gte($start) && $now->lte($dueEnd)) {
                return [$m, $y];
            }

            $cursor->subMonthNoOverflow();
        }

        $fallback = $now->copy()->subMonthNoOverflow();

        return [(int) $fallback->month, (int) $fallback->year];
    }

    public function currentOpenPeriodLabel(): string
    {
        [$m, $y] = $this->currentOpenPeriod();

        return $this->periodLabel($m, $y);
    }

    public function contributionCycleKey(int $month, int $year): string
    {
        return sprintf('%04d-%02d', $year, $month);
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function parseContributionCycleKey(string $key): array
    {
        if (! preg_match('/^(\d{4})-(\d{2})$/', $key, $m)) {
            throw new \InvalidArgumentException('Invalid contribution cycle key.');
        }

        return [(int) $m[2], (int) $m[1]];
    }

    public function memberIsLiableForContributionPeriod(Member $member, int $month, int $year): bool
    {
        if ($member->status !== 'active' || (float) $member->monthly_contribution_amount <= 0) {
            return false;
        }

        if ($member->isExemptFromContributions($month, $year)) {
            return false;
        }

        if ($member->joined_at === null) {
            return true;
        }

        $periodStart = Carbon::create($year, $month, 1)->startOfMonth();
        $joinedStart = $member->joined_at->copy()->startOfMonth();

        return $periodStart->greaterThanOrEqualTo($joinedStart);
    }

    public function memberCanApplyContributionForPeriod(Member $member, int $month, int $year): bool
    {
        if (! $this->memberIsLiableForContributionPeriod($member, $month, $year)) {
            return false;
        }

        return ! Contribution::query()
            ->where('member_id', $member->id)
            ->forPeriod($month, $year)
            ->posted()
            ->exists();
    }

    public function pendingMembersQueryForPeriod(int $month, int $year): Builder
    {
        return Member::query()
            ->active()
            ->where('monthly_contribution_amount', '>', 0)
            ->whereDoesntHave('contributions', function (Builder $query) use ($month, $year): void {
                $query->forPeriod($month, $year)->posted();
            })
            ->where(function (Builder $query) use ($month, $year): void {
                $periodStart = Carbon::create($year, $month, 1)->startOfMonth();
                $query->whereNull('joined_at')
                    ->orWhere('joined_at', '<=', $periodStart->copy()->endOfMonth());
            })
            ->whereDoesntHave('loans', function (Builder $loan): void {
                $loan->where('status', 'active')
                    ->whereHas('installments', fn (Builder $installment): Builder => $installment->whereIn('status', ['pending', 'overdue']));
            })
            ->with(['parent', 'cashAccount'])
            ->orderBy('name');
    }

    public function postedContributionsQueryForPeriod(int $month, int $year): Builder
    {
        return Contribution::query()
            ->forPeriod($month, $year)
            ->posted()
            ->with('member')
            ->orderByDesc('posted_at');
    }

    /**
     * Members who should have a posted collection for the period (posted + still outstanding).
     *
     * @return array{expected_count: int, expected_amount: float}
     */
    public function expectedCollectionTargetsForPeriod(int $month, int $year): array
    {
        $postedMemberIds = Contribution::query()
            ->forPeriod($month, $year)
            ->posted()
            ->pluck('member_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $missingMembers = $this->pendingMembersQueryForPeriod($month, $year)
            ->get(['id', 'monthly_contribution_amount']);

        $memberIds = array_values(array_unique(array_merge(
            $postedMemberIds,
            $missingMembers->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
        )));

        if ($memberIds === []) {
            return [
                'expected_count' => 0,
                'expected_amount' => 0.0,
            ];
        }

        return [
            'expected_count' => count($memberIds),
            'expected_amount' => (float) Member::query()
                ->whereIn('id', $memberIds)
                ->sum('monthly_contribution_amount'),
        ];
    }

    /**
     * Cash required to apply the open-period contribution (amount + late fee when applicable).
     */
    public function requiredCashForMemberPeriod(Member $member, int $month, int $year): float
    {
        $amount = (float) $member->monthly_contribution_amount;
        $lateFee = $this->lateFeeForContributionPeriod($month, $year);

        return $amount + $lateFee;
    }

    /**
     * @return array<string, string>
     */
    public function defaultContributionCycleKeyForMember(Member $member): ?string
    {
        $opts = $this->contributionCycleSelectOptionsForMember($member);

        if ($opts === []) {
            return null;
        }

        [$curM, $curY] = $this->currentOpenPeriod();
        $preferred = $this->contributionCycleKey($curM, $curY);

        return isset($opts[$preferred]) ? $preferred : array_key_first($opts);
    }

    public function contributionCycleSelectOptionsForMember(Member $member): array
    {
        $options = [];
        [$curM, $curY] = $this->currentOpenPeriod();
        $cursor = Carbon::create($curY, $curM, 1)->startOfMonth();

        for ($i = 0; $i < self::CONTRIBUTION_CYCLE_LOOKBACK_MONTHS; $i++) {
            $m = (int) $cursor->month;
            $y = (int) $cursor->year;

            if ($this->memberCanApplyContributionForPeriod($member, $m, $y)) {
                $options[$this->contributionCycleKey($m, $y)] = $this->periodLabel($m, $y);
            }

            $cursor->subMonthNoOverflow();
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function contributionCycleSelectOptionsForBulk(): array
    {
        $options = [];
        [$curM, $curY] = $this->currentOpenPeriod();
        $cursor = Carbon::create($curY, $curM, 1)->startOfMonth();

        for ($i = 0; $i < self::CONTRIBUTION_CYCLE_LOOKBACK_MONTHS; $i++) {
            $m = (int) $cursor->month;
            $y = (int) $cursor->year;
            $options[$this->contributionCycleKey($m, $y)] = $this->periodLabel($m, $y);
            $cursor->subMonthNoOverflow();
        }

        return $options;
    }

    public function lateFeeForContributionPeriod(int $month, int $year, ?Carbon $at = null): float
    {
        $at = $at ?? BusinessDay::now();
        $deadline = $this->deadline($month, $year);
        $days = $this->lateFees->daysPastDue($deadline, $at);

        return $this->lateFees->contributionLateFeeForDays($days);
    }

    /**
     * Cash required to settle a member's open or not-yet-created contribution for a period.
     */
    public function requiredCollectionCashForMemberPeriod(Member $member, int $month, int $year): float
    {
        if ($member->isExemptFromContributions($month, $year) || (float) $member->monthly_contribution_amount <= 0) {
            return 0.0;
        }

        $contribution = Contribution::query()
            ->where('member_id', $member->id)
            ->forPeriod($month, $year)
            ->where('status', 'pending')
            ->first();

        if ($contribution !== null) {
            app(ContributionCollectionCycleService::class)
                ->syncContributionLateFeesBeforeCollection($contribution);

            $contribution = $contribution->fresh();
            $principalShortfall = max(
                0.0,
                (float) ($contribution->amount_due ?? $contribution->amount) - (float) ($contribution->amount_collected ?? 0),
            );
            $lateFeeAssessed = (float) ($contribution->late_fee_amount ?? 0);
            $lateFeeDue = max(0.0, $lateFeeAssessed - $this->accounting->contributionLateFeeCollectedAmount($contribution));

            return $principalShortfall + $lateFeeDue;
        }

        if (! $this->memberCanApplyContributionForPeriod($member, $month, $year)) {
            return 0.0;
        }

        return $this->requiredCashForMemberPeriod($member, $month, $year);
    }

    public function sendDueNotifications(int $month, int $year): int
    {
        $deadline = $this->deadline($month, $year);
        $notified = 0;

        Member::active()->with('user')->each(function (Member $member) use ($month, $year, $deadline, &$notified): void {
            $alreadyPaid = Contribution::query()
                ->where('member_id', $member->id)
                ->forPeriod($month, $year)
                ->exists();

            if ($alreadyPaid || $member->isExemptFromContributions()) {
                return;
            }

            $user = $member->user;

            if ($user === null) {
                return;
            }

            try {
                $user->notify(new ContributionDueNotification(
                    month: $month,
                    year: $year,
                    amount: (float) $member->monthly_contribution_amount,
                    deadline: $deadline,
                    cashBalance: $member->getCashBalance(),
                ));
                $notified++;
            } catch (\Throwable $e) {
                logger()->error('ContributionCycleService: notification failed', [
                    'member_id' => $member->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        return $notified;
    }

    /**
     * @return array{applied: Collection, insufficient: Collection, skipped: Collection}
     */
    public function applyContributions(int $month, int $year): array
    {
        return app(ContributionService::class)->applyContributionsForPeriod($month, $year);
    }

    public function applyContributionForMemberForPeriod(Member $member, int $month, int $year): string
    {
        $bucket = [];

        return app(ContributionService::class)->applyForPeriod($member, $month, $year, $bucket);
    }

    public function dependentAllocationExistsForPeriod(Member $dependent, int $month, int $year): bool
    {
        return DependentCashAllocation::query()
            ->where('dependent_member_id', $dependent->id)
            ->where('allocation_month', $month)
            ->where('allocation_year', $year)
            ->exists();
    }

    /**
     * @return array{transfers: int, details: list<string>, allocated_dependent_ids: list<int>}
     */
    public function applyDependentAllocationForParentForPeriod(Member $parent, int $month, int $year): array
    {
        $details = [];
        $transfers = 0;
        $allocatedDependentIds = [];
        $periodLabel = $this->periodLabel($month, $year);

        if (! $parent->dependents()->where('status', 'active')->exists()) {
            return [
                'transfers' => 0,
                'details' => [__('This member has no active dependents.')],
                'allocated_dependent_ids' => [],
            ];
        }

        $parent->load(['dependents']);

        foreach ($parent->dependents()->where('status', 'active')->orderBy('member_number')->get() as $dependent) {
            if ($dependent->isExemptFromContributions($month, $year) || (float) $dependent->monthly_contribution_amount <= 0) {
                continue;
            }

            $required = $this->requiredCollectionCashForMemberPeriod($dependent, $month, $year);

            if ($required <= 0.00001) {
                continue;
            }

            if ($dependent->getCashBalance() >= $required - 0.00001) {
                continue;
            }

            if ($this->dependentAllocationExistsForPeriod($dependent, $month, $year)) {
                $details[] = "{$dependent->name}: ".__('Allocation already completed for :period; collect from dependent cash.', ['period' => $periodLabel]);

                continue;
            }

            if ($parent->getCashBalance() < $required - 0.00001) {
                $details[] = "{$dependent->name}: ".__('Parent cash insufficient for full allocation (:amount).', [
                    'amount' => number_format($required, 2),
                ]);

                continue;
            }

            try {
                DB::transaction(function () use ($parent, $dependent, $required, $periodLabel, $month, $year): void {
                    $this->accounting->fundDependentCashAccount(
                        $parent,
                        $dependent,
                        $required,
                        __('Allocation — :period', ['period' => $periodLabel]),
                    );

                    DependentCashAllocation::query()->create([
                        'parent_member_id' => $parent->id,
                        'dependent_member_id' => $dependent->id,
                        'allocation_month' => $month,
                        'allocation_year' => $year,
                        'amount' => $required,
                    ]);
                });
                $transfers++;
                $allocatedDependentIds[] = $dependent->id;
                $details[] = "{$dependent->name}: ".__('Transferred :amount for :period.', [
                    'amount' => number_format($required, 2),
                    'period' => $periodLabel,
                ]);
            } catch (RuntimeException $e) {
                $details[] = "{$dependent->name}: {$e->getMessage()}";
            }
        }

        return [
            'transfers' => $transfers,
            'details' => $details,
            'allocated_dependent_ids' => $allocatedDependentIds,
        ];
    }
}
