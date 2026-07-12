<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Support\MoneyDisplay;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\DependentCashAllocation;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Notifications\Tenant\ContributionDueNotification;
use App\Services\Loans\LateFeeService;
use App\Services\Loans\LoanEmiCollectionCatalogService;
use App\Support\BusinessDay;
use App\Support\ContributionExemptionPolicy;
use App\Support\MemberMembershipPolicy;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
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
     * Whether a loan's repayment span overlaps the labelled contribution cycle window.
     *
     * Uses {@see cycleStartAt()} / {@see cycleDueEndAt()} so tenant cycle_start_day is respected.
     */
    public function loanRepaymentOverlapsContributionCycle(
        Carbon|string|null $disbursedAt,
        Carbon|string|null $settledAt,
        Carbon|string|null $completedAt,
        string $status,
        int $month,
        int $year,
        ?int $firstRepaymentMonth = null,
        ?int $firstRepaymentYear = null,
    ): bool {
        if ($disbursedAt === null || $disbursedAt === '') {
            return false;
        }

        $disbursed = Carbon::parse($disbursedAt)->startOfDay();
        $cycleStart = $this->cycleStartAt($month, $year)->startOfDay();
        $cycleEnd = $this->cycleDueEndAt($month, $year)->startOfDay();

        if ($disbursed->greaterThan($cycleEnd)) {
            return false;
        }

        if ($firstRepaymentMonth !== null && $firstRepaymentYear !== null) {
            $emiStart = $this->cycleStartAt($firstRepaymentMonth, $firstRepaymentYear)->startOfDay();

            if ($cycleStart->lessThan($emiStart)) {
                return false;
            }
        }

        $repaymentEnd = $settledAt ?? $completedAt;

        if ($repaymentEnd === null || $repaymentEnd === '') {
            return in_array($status, ['active', 'transferred'], true);
        }

        return Carbon::parse($repaymentEnd)->startOfDay()->greaterThanOrEqualTo($cycleStart);
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
        if (! app(MemberMembershipPolicy::class)->canParticipateInContributionCycles($member)) {
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
        $policy = app(ContributionExemptionPolicy::class);

        $candidateIds = Member::query()
            ->contributionCycleEligible()
            ->collectibleForContributionPeriod($month, $year)
            ->whereDoesntHave('contributions', function (Builder $query) use ($month, $year): Builder {
                return $query->forPeriod($month, $year)->posted();
            })
            ->where(function (Builder $query) use ($month, $year): Builder {
                $periodStart = Carbon::create($year, $month, 1)->startOfMonth();

                return $query->whereNull('joined_at')
                    ->orWhere('joined_at', '<=', $periodStart->copy()->endOfMonth());
            })
            ->with(['parent', 'cashAccount', 'loans'])
            ->get()
            ->reject(fn (Member $member): bool => $policy->isContributionExemptForCycle($member, $month, $year))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($candidateIds === []) {
            return Member::query()->whereRaw('0 = 1');
        }

        return Member::query()
            ->whereIn('id', $candidateIds)
            ->with(['parent', 'cashAccount']);
    }

    public function postedContributionsQueryForPeriod(int $month, int $year): Builder
    {
        return Contribution::query()
            ->forPeriod($month, $year)
            ->posted()
            ->with('member');
    }

    public function postedContributionCount(int $month, int $year): int
    {
        return $this->postedContributionsQueryForPeriod($month, $year)->count();
    }

    /**
     * Member ids that appear on the cycle To collect / Collected workspaces for export.
     *
     * @return list<int>
     */
    public function summaryExportMemberIds(int $month, int $year): array
    {
        $pendingIds = $this->pendingMembersQueryForPeriod($month, $year)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $postedIds = $this->postedContributionsQueryForPeriod($month, $year)
            ->whereHas('member', fn (Builder $query): Builder => $query->contributionCycleEligible())
            ->pluck('member_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return array_values(array_unique([...$pendingIds, ...$postedIds]));
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

    /**
     * @return array{visible: array<string, string>, more: array<string, string>}
     */
    public function contributionCyclePillGroups(int $visibleCount = 6): array
    {
        $all = $this->contributionCycleSelectOptionsForBulk();

        return [
            'visible' => array_slice($all, 0, $visibleCount, true),
            'more' => array_slice($all, $visibleCount, null, true),
        ];
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

    /**
     * Total cash a member should hold for the period: own contribution shortfall
     * plus, for parents, dependent contribution/EMI allocation shortfalls.
     */
    public function dueNotificationAmountForMember(Member $member, int $month, int $year): float
    {
        $amount = 0.0;

        if (! $member->isExemptFromContributions($month, $year)) {
            $amount += $this->requiredCollectionCashForMemberPeriod($member, $month, $year);
        }

        if ($member->parent_member_id === null) {
            $member->loadMissing(['dependents']);
            $amount += $this->totalDependentShortfallForParentForPeriod($member, $month, $year);
        }

        return round($amount, 2);
    }

    /**
     * @return array{notified: int, skipped_paid: int, skipped_exempt: int, skipped_no_user: int, failed: int}
     */
    public function sendDueNotifications(int $month, int $year): array
    {
        $deadline = $this->deadline($month, $year)->copy()->startOfDay();
        $stats = [
            'notified' => 0,
            'skipped_paid' => 0,
            'skipped_exempt' => 0,
            'skipped_no_user' => 0,
            'failed' => 0,
        ];

        Member::active()->with(['user', 'dependents'])->each(function (Member $member) use ($month, $year, $deadline, &$stats): void {
            $amount = $this->dueNotificationAmountForMember($member, $month, $year);

            if ($amount <= 0.00001) {
                if ($member->isExemptFromContributions($month, $year) && $member->parent_member_id !== null) {
                    $stats['skipped_exempt']++;
                } else {
                    $stats['skipped_paid']++;
                }

                return;
            }

            $user = $member->user;

            if ($user === null) {
                $stats['skipped_no_user']++;

                return;
            }

            try {
                $user->notify(new ContributionDueNotification(
                    month: $month,
                    year: $year,
                    amount: $amount,
                    deadline: $deadline,
                    cashBalance: $member->getCashBalance(),
                    memberName: (string) $member->name,
                ));
                $stats['notified']++;
            } catch (\Throwable $e) {
                $stats['failed']++;
                logger()->error('ContributionCycleService: notification failed', [
                    'member_id' => $member->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        return $stats;
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

    public function memberBaseEligibleForDependentAllocation(Member $dependent, int $month, int $year): bool
    {
        if ($dependent->status !== 'active') {
            return false;
        }

        return $this->dependentCycleDuesForPeriod($dependent, $month, $year) > 0.00001;
    }

    public function dependentCycleDuesForPeriod(Member $member, int $month, int $year): float
    {
        $dues = 0.0;

        if ($member->excludesHouseholdContributionFunding()) {
            return 0.0;
        }

        if (
            (float) $member->monthly_contribution_amount > 0
            && ! $member->isExemptFromContributions($month, $year)
        ) {
            $dues += $this->requiredCollectionCashForMemberPeriod($member, $month, $year);
        }

        $dues += app(LoanEmiCollectionCatalogService::class)->requiredCashForMember($member, $month, $year);

        return $dues;
    }

    public function memberEligibleForDependentAllocationFunding(Member $dependent, int $month, int $year): bool
    {
        if (! $this->memberBaseEligibleForDependentAllocation($dependent, $month, $year)) {
            return false;
        }

        return ! $this->dependentAllocationExistsForPeriod($dependent, $month, $year);
    }

    public function dependentAllocationShortfallForPeriod(Member $dependent, int $month, int $year): float
    {
        if (! $this->memberEligibleForDependentAllocationFunding($dependent, $month, $year)) {
            return 0.0;
        }

        $required = $this->dependentCycleDuesForPeriod($dependent, $month, $year);

        return max(0.0, $required - $dependent->getCashBalance());
    }

    public function totalDependentShortfallForParentForPeriod(Member $parent, int $month, int $year): float
    {
        $parent->loadMissing(['dependents']);

        $total = 0.0;

        foreach ($parent->dependents()->where('status', 'active')->orderBy('member_number')->get() as $dependent) {
            $total += $this->dependentAllocationShortfallForPeriod($dependent, $month, $year);
        }

        return $total;
    }

    /**
     * @return array<string, string>
     */
    public function allocationCycleSelectOptionsForParent(Member $parent): array
    {
        $options = [];
        [$curM, $curY] = $this->currentOpenPeriod();
        $cursor = Carbon::create($curY, $curM, 1)->startOfMonth();

        for ($i = 0; $i < self::CONTRIBUTION_CYCLE_LOOKBACK_MONTHS; $i++) {
            $m = (int) $cursor->month;
            $y = (int) $cursor->year;
            $total = $this->totalDependentShortfallForParentForPeriod($parent, $m, $y);

            if ($total <= 0.00001) {
                $cursor->subMonthNoOverflow();

                continue;
            }

            if ($parent->getCashBalance() < $total - 0.00001) {
                $cursor->subMonthNoOverflow();

                continue;
            }

            $options[$this->contributionCycleKey($m, $y)] = $this->periodLabel($m, $y);
            $cursor->subMonthNoOverflow();
        }

        return $options;
    }

    public function defaultAllocationCycleKeyForParent(Member $parent): ?string
    {
        $options = $this->allocationCycleSelectOptionsForParent($parent);

        if ($options === []) {
            return null;
        }

        [$curM, $curY] = $this->currentOpenPeriod();
        $preferred = $this->contributionCycleKey($curM, $curY);

        return isset($options[$preferred]) ? $preferred : array_key_first($options);
    }

    public function shouldShowDependentAllocationAction(Member $parent): bool
    {
        if ($parent->status !== 'active') {
            return false;
        }

        if (! $parent->dependents()->where('status', 'active')->exists()) {
            return false;
        }

        return $this->allocationCycleSelectOptionsForParent($parent) !== [];
    }

    public function dependentAllocationModalDescriptionForPeriod(Member $parent, int $month, int $year): HtmlString
    {
        $parent->loadMissing(['dependents']);
        $currency = Setting::get('general', 'currency', 'USD');
        $parentCash = $parent->getCashBalance();
        $periodLabel = $this->periodLabel($month, $year);
        $totalShortfall = $this->totalDependentShortfallForParentForPeriod($parent, $month, $year);

        $rowsHtml = '';

        foreach ($parent->dependents()->where('status', 'active')->orderBy('member_number')->get() as $dependent) {
            $shortfall = $this->dependentAllocationShortfallForPeriod($dependent, $month, $year);

            if ($shortfall <= 0.00001) {
                continue;
            }

            $required = $this->dependentCycleDuesForPeriod($dependent, $month, $year);
            $cash = $dependent->getCashBalance();
            $name = e($dependent->name);

            $rowsHtml .= '<tr class="border-b border-gray-100 last:border-0 dark:border-white/10">'
                .'<td class="py-2.5 pr-3 text-gray-950 dark:text-white">'.$name.'</td>'
                .'<td class="py-2.5 pe-3 text-end tabular-nums text-gray-700 dark:text-gray-300">'.(MoneyDisplay::html($cash, $currency)?->toHtml() ?? '').'</td>'
                .'<td class="py-2.5 pe-3 text-end tabular-nums text-gray-700 dark:text-gray-300">'.(MoneyDisplay::html($required, $currency)?->toHtml() ?? '').'</td>'
                .'<td class="py-2.5 text-end tabular-nums font-medium text-gray-950 dark:text-white">'.(MoneyDisplay::html($shortfall, $currency)?->toHtml() ?? '').'</td>'
                .'</tr>';
        }

        if ($rowsHtml === '') {
            $summary = '<p class="text-sm text-gray-600 dark:text-gray-400">'
                .e(__('Your cash balance: :balance', ['balance' => MoneyDisplay::format($parentCash, $currency) ?? '']))
                .'</p>'
                .'<p class="mt-2 text-sm text-gray-600 dark:text-gray-400">'
                .e(__('No dependent shortfalls for :period.', ['period' => $periodLabel]))
                .'</p>';

            return new HtmlString('<div class="space-y-1 text-sm">'.$summary.'</div>');
        }

        $table = '<div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900/40">'
            .'<table class="w-full min-w-[22rem] text-sm">'
            .'<thead><tr class="border-b border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">'
            .'<th scope="col" class="py-2.5 ps-3 pe-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">'.e(__('Dependent')).'</th>'
            .'<th scope="col" class="py-2.5 pe-3 text-end text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">'.e(__('Cash')).'</th>'
            .'<th scope="col" class="py-2.5 pe-3 text-end text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">'.e(__('Needed')).'</th>'
            .'<th scope="col" class="py-2.5 pe-3 text-end text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">'.e(__('Transfer')).'</th>'
            .'</tr></thead>'
            .'<tbody>'.$rowsHtml.'</tbody>'
            .'</table></div>';

        $html = '<div class="space-y-4 text-sm">'
            .'<p class="text-gray-600 dark:text-gray-400">'
            .e(__('Your cash balance: :balance · Total to transfer: :total', [
                'balance' => MoneyDisplay::format($parentCash, $currency) ?? '',
                'total' => MoneyDisplay::format($totalShortfall, $currency) ?? '',
            ]))
            .'</p>'
            .$table
            .'<p class="text-xs leading-relaxed text-gray-500 dark:text-gray-400">'
            .e(__('Confirming will move each transfer amount from your cash to that dependent\'s cash for :period.', [
                'period' => $periodLabel,
            ]))
            .'</p>'
            .'</div>';

        return new HtmlString($html);
    }

    /**
     * @param  list<string>  $lines
     */
    public function formatAllocationResultDetailTableHtml(array $lines): HtmlString
    {
        if ($lines === []) {
            return new HtmlString('');
        }

        $rows = '';

        foreach ($lines as $line) {
            if (! str_contains($line, ':')) {
                $rows .= '<tr><td colspan="2" class="py-2 text-sm text-gray-600 dark:text-gray-400">'.e($line).'</td></tr>';

                continue;
            }

            [$name, $detail] = explode(':', $line, 2);
            $rows .= '<tr class="border-b border-gray-100 last:border-0 dark:border-white/10">'
                .'<td class="py-2 pr-3 align-top font-medium text-gray-950 dark:text-white">'.e(trim($name)).'</td>'
                .'<td class="py-2 align-top text-gray-600 dark:text-gray-400">'.e(trim($detail)).'</td>'
                .'</tr>';
        }

        return new HtmlString(
            '<div class="overflow-x-auto rounded-lg border border-gray-200 bg-white text-start dark:border-white/10 dark:bg-gray-900/40">'
            .'<table class="w-full min-w-[16rem] text-sm">'
            .'<thead><tr class="border-b border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">'
            .'<th scope="col" class="py-2 ps-3 pe-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">'.e(__('Dependent')).'</th>'
            .'<th scope="col" class="py-2 pe-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">'.e(__('Result')).'</th>'
            .'</tr></thead>'
            .'<tbody>'.$rows.'</tbody>'
            .'</table></div>'
        );
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
        $currency = Setting::get('general', 'currency', 'USD');
        $totalShortfall = $this->totalDependentShortfallForParentForPeriod($parent, $month, $year);

        if ($totalShortfall <= 0.00001) {
            return [
                'transfers' => 0,
                'details' => [__('No dependent shortfalls to cover for :period.', ['period' => $periodLabel])],
                'allocated_dependent_ids' => [],
            ];
        }

        if ($parent->getCashBalance() < $totalShortfall - 0.00001) {
            return [
                'transfers' => 0,
                'details' => [
                    __('Parent cash (:cash) is insufficient to cover total shortfalls (:total).', [
                        'cash' => MoneyDisplay::format($parent->getCashBalance(), $currency) ?? '',
                        'total' => MoneyDisplay::format($totalShortfall, $currency) ?? '',
                    ]),
                ],
                'allocated_dependent_ids' => [],
            ];
        }

        foreach ($parent->dependents()->where('status', 'active')->orderBy('member_number')->get() as $dependent) {
            if ($this->dependentAllocationExistsForPeriod($dependent, $month, $year)) {
                $details[] = $this->dependentAllocationDetailLine(
                    $dependent,
                    __('Allocation for :period was already completed.', ['period' => $periodLabel]),
                );

                continue;
            }

            if (! $this->memberBaseEligibleForDependentAllocation($dependent, $month, $year)) {
                continue;
            }

            $shortfall = $this->dependentAllocationShortfallForPeriod($dependent, $month, $year);

            if ($shortfall <= 0.00001) {
                $required = $this->dependentCycleDuesForPeriod($dependent, $month, $year);
                $details[] = $this->dependentAllocationDetailLine(
                    $dependent,
                    __('Cash already covers :amount.', [
                        'amount' => MoneyDisplay::format($required, $currency) ?? '',
                    ]),
                );

                continue;
            }

            try {
                DB::transaction(function () use ($parent, $dependent, $shortfall, $periodLabel, $month, $year): void {
                    $this->accounting->fundDependentCashAccount(
                        $parent,
                        $dependent,
                        $shortfall,
                        __('Allocation — :period', ['period' => $periodLabel]),
                    );

                    DependentCashAllocation::query()->create([
                        'parent_member_id' => $parent->id,
                        'dependent_member_id' => $dependent->id,
                        'allocation_month' => $month,
                        'allocation_year' => $year,
                        'amount' => $shortfall,
                    ]);
                });
                $transfers++;
                $allocatedDependentIds[] = $dependent->id;
                $details[] = $this->dependentAllocationDetailLine(
                    $dependent,
                    __('Transferred :amount for :period.', [
                        'amount' => MoneyDisplay::format($shortfall, $currency) ?? '',
                        'period' => $periodLabel,
                    ]),
                );
            } catch (RuntimeException $e) {
                $details[] = $this->dependentAllocationDetailLine($dependent, $e->getMessage());
            }
        }

        if ($transfers === 0 && $details === []) {
            $details[] = __('No dependent shortfalls to cover for :period.', ['period' => $periodLabel]);
        }

        return [
            'transfers' => $transfers,
            'details' => $details,
            'allocated_dependent_ids' => $allocatedDependentIds,
        ];
    }

    private function dependentAllocationDetailLine(Member $dependent, string $message): string
    {
        return $dependent->name.': '.$message;
    }
}
