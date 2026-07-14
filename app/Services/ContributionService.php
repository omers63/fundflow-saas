<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Notifications\Tenant\ContributionPostedNotification;
use App\Services\Loans\LateFeeService;
use App\Services\Loans\LoanRepaymentService;
use App\Support\BusinessDay;
use App\Support\ContributionCollectionStatus;
use App\Support\LoanSettings;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ContributionService
{
    private static int $postedNotificationDepth = 0;

    private static int $liveCollectionGuardDepth = 0;

    public function __construct(
        public AccountingService $accounting,
        public ContributionCycleService $cycles,
        public LateFeeService $lateFees,
        public ContributionCollectionCycleService $collectionCycle,
    ) {}

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutPostedNotifications(callable $callback): mixed
    {
        self::$postedNotificationDepth++;

        try {
            return $callback();
        } finally {
            self::$postedNotificationDepth--;
        }
    }

    public static function postedNotificationsSuppressed(): bool
    {
        return self::$postedNotificationDepth > 0;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutLiveCollectionGuards(callable $callback): mixed
    {
        self::$liveCollectionGuardDepth++;

        try {
            return $callback();
        } finally {
            self::$liveCollectionGuardDepth--;
        }
    }

    public static function liveCollectionGuardsSuppressed(): bool
    {
        return self::$liveCollectionGuardDepth > 0;
    }

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
        [$month, $year] = Contribution::monthYearFromPeriod($period);

        $existing = Contribution::findForMemberPeriod($member->id, $month, $year);

        if ($existing !== null) {
            return $existing;
        }

        if (Contribution::memberPeriodRecordExists($member->id, $month, $year)) {
            throw ValidationException::withMessages([
                'period' => [
                    __('A deleted contribution still occupies :period. Restore it or permanently remove it before creating a new one.', [
                        'period' => $this->periodLabel($month, $year),
                    ]),
                ],
            ]);
        }

        $due = (float) ($amount ?? $member->monthly_contribution_amount);

        try {
            return Contribution::create([
                'member_id' => $member->id,
                'period' => Contribution::periodDate($month, $year),
                'amount' => $due,
                'amount_due' => $due,
                'amount_collected' => 0,
                'status' => 'pending',
                'collection_status' => ContributionCollectionStatus::PENDING,
                'payment_method' => Contribution::PAYMENT_METHOD_ADMIN,
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = Contribution::findForMemberPeriod($member->id, $month, $year);

            if ($existing !== null) {
                return $existing;
            }

            throw ValidationException::withMessages([
                'period' => [
                    __('A contribution already exists for :period.', [
                        'period' => $this->periodLabel($month, $year),
                    ]),
                ],
            ]);
        }
    }

    public function postContribution(Contribution $contribution, ?CarbonInterface $postedAt = null): void
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

        DB::transaction(function () use ($contribution, $postedAt): void {
            $effectivePostedAt = $postedAt ?? BusinessDay::now();
            $this->accounting->postContribution($contribution, $effectivePostedAt);

            $contribution->update([
                'status' => 'posted',
                'posted_at' => $effectivePostedAt,
                'paid_at' => $contribution->paid_at ?? $effectivePostedAt,
            ]);
        });

        if (LoanSettings::autoAllocateLoanRepayment() && ! self::postedNotificationsSuppressed()) {
            app(LoanRepaymentService::class)->applyOpenPeriodRepaymentForMember($contribution->member);
        }

        if (! self::postedNotificationsSuppressed()) {
            $this->notifyMemberOfPostedContribution($contribution);
        }
    }

    public function notifyMemberOfPostedContribution(Contribution $contribution): void
    {
        $contribution->loadMissing('member.user');

        $user = $contribution->member?->user;

        if ($user === null) {
            return;
        }

        try {
            $user->notify(new ContributionPostedNotification($contribution->fresh()));
        } catch (\Throwable $exception) {
            logger()->error('ContributionService: posted notification failed', [
                'contribution_id' => $contribution->id,
                'member_id' => $contribution->member_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Apply (create + post) a cycle contribution for one member.
     *
     * @param  array<string, mixed>  $results
     */
    public function applyForPeriod(Member $member, int $month, int $year, array &$results = []): string
    {
        if ($member->isExemptFromContributions($month, $year)) {
            $results['skipped'][] = $member;

            return 'exempt';
        }

        $amount = (float) $member->monthly_contribution_amount;

        if ($amount <= 0) {
            $results['skipped'][] = $member;

            return 'exempt';
        }

        $contribution = $this->resolveContributionForPeriodApply($member, $month, $year, $amount, $results);

        if ($contribution === null) {
            return 'already_contributed';
        }

        $deadline = $this->cycles->deadline($month, $year);

        if (BusinessDay::now()->greaterThan($deadline) && $contribution->overdue_since === null) {
            $contribution->update([
                'collection_status' => ContributionCollectionStatus::OVERDUE,
                'overdue_since' => $deadline,
                'is_late' => true,
            ]);
            $this->collectionCycle->applyLateFeeTierForContribution($contribution->fresh());
            $contribution = $contribution->fresh();
        }

        try {
            $outcome = $this->collectionCycle->attemptCollection($contribution, manualApply: true);
        } catch (UniqueConstraintViolationException|ValidationException) {
            $results['skipped'][] = $member;

            return 'already_contributed';
        } catch (InvalidArgumentException $exception) {
            $results['insufficient'][] = [
                'member' => $member,
                'balance' => $member->getCashBalance(),
                'required' => (float) ($contribution->amount_due ?? $amount),
            ];

            return 'insufficient';
        }

        if ($outcome === 'collected' || $outcome === 'partial') {
            $results['applied'][] = $member;

            return $outcome === 'partial' ? 'partial' : 'applied';
        }

        if ($outcome === 'insufficient') {
            $results['insufficient'][] = [
                'member' => $member,
                'balance' => $member->getCashBalance(),
                'required' => max(0, (float) ($contribution->amount_due ?? 0) - (float) ($contribution->amount_collected ?? 0)),
            ];

            return 'insufficient';
        }

        return 'skipped';
    }

    /**
     * @param  array<string, mixed>  $results
     */
    private function resolveContributionForPeriodApply(
        Member $member,
        int $month,
        int $year,
        float $amount,
        array &$results,
    ): ?Contribution {
        if (Contribution::periodFullyPosted($member->id, $month, $year)) {
            $results['skipped'][] = $member;

            return null;
        }

        $contribution = Contribution::findForMemberPeriod($member->id, $month, $year, withTrashed: true);

        if ($contribution?->trashed()) {
            $contribution->forceDelete();
            $contribution = null;
        }

        if ($contribution !== null) {
            return $this->cycles->syncPendingContributionDueToCurrentStanding($contribution, $member);
        }

        $period = Contribution::periodDate($month, $year);

        try {
            return Contribution::create([
                'member_id' => $member->id,
                'period' => $period,
                'amount' => $amount,
                'amount_due' => $amount,
                'amount_collected' => 0,
                'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
                'status' => 'pending',
                'collection_status' => ContributionCollectionStatus::PENDING,
                'cycle_open_cash_balance' => $member->getCashBalance(),
            ]);
        } catch (UniqueConstraintViolationException|ValidationException) {
            $contribution = Contribution::findForMemberPeriod($member->id, $month, $year, withTrashed: true);

            if ($contribution?->trashed()) {
                $contribution->forceDelete();

                try {
                    return Contribution::create([
                        'member_id' => $member->id,
                        'period' => $period,
                        'amount' => $amount,
                        'amount_due' => $amount,
                        'amount_collected' => 0,
                        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
                        'status' => 'pending',
                        'collection_status' => ContributionCollectionStatus::PENDING,
                        'cycle_open_cash_balance' => $member->getCashBalance(),
                    ]);
                } catch (UniqueConstraintViolationException|ValidationException) {
                    $contribution = Contribution::findForMemberPeriod($member->id, $month, $year, withTrashed: true);
                }
            }

            if ($contribution === null) {
                $results['skipped'][] = $member;

                return null;
            }

            if ($contribution->trashed()) {
                $contribution->forceDelete();
                $results['skipped'][] = $member;

                return null;
            }

            return $contribution;
        }
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

        $householdMemberIds = [];
        $collectionCycle = app(ContributionCollectionCycleService::class);

        Member::query()
            ->active()
            ->whereNull('parent_member_id')
            ->whereHas('dependents', fn ($query) => $query->where('status', 'active'))
            ->with(['dependents' => fn ($query) => $query->where('status', 'active')->orderBy('member_number')])
            ->orderBy('id')
            ->each(function (Member $parent) use ($month, $year, &$results, &$householdMemberIds, $collectionCycle): void {
                $dependents = $parent->dependents;

                $collectionCycle->applyHouseholdContributionsForPeriod($parent, $dependents, $month, $year, $results);

                $householdMemberIds[] = $parent->id;

                foreach ($dependents as $dependent) {
                    $householdMemberIds[] = $dependent->id;
                }
            });

        Member::active()
            ->with('user')
            ->when($householdMemberIds !== [], fn ($query) => $query->whereNotIn('id', $householdMemberIds))
            ->each(function (Member $member) use ($month, $year, &$results): void {
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

        return $this->collectionCycle->initializeOpenPeriod($month, $year);
    }

    public function periodLabel(int $month, int $year): string
    {
        return $this->cycles->periodLabel($month, $year);
    }

    public static function deleteModalDescription(Contribution $contribution): string
    {
        $period = $contribution->period?->translatedFormat('F Y') ?? __('this period');

        if ($contribution->status === 'posted') {
            return __('This removes the :period contribution and posts reversing entries on member cash, fund, and related master accounts.', [
                'period' => $period,
            ]);
        }

        $lateFeeCollected = app(AccountingService::class)->contributionLateFeeCollectedAmount($contribution);

        if ($lateFeeCollected > 0.00001) {
            return __('This removes the :period contribution and reverses :amount in late fees already debited from cash.', [
                'period' => $period,
                'amount' => number_format($lateFeeCollected, 2),
            ]);
        }

        return __('This permanently removes the :period contribution record.', [
            'period' => $period,
        ]);
    }

    public function deleteContribution(Contribution $contribution): void
    {
        if (! $contribution->isDeletableByAdmin()) {
            throw new InvalidArgumentException(__('Cycle and import contributions cannot be deleted. Use reconciliation or reversal tools to correct ledger entries.'));
        }

        $this->reverseAndDeleteContribution($contribution);
    }

    /**
     * Reverse ledger legs and remove a legacy-import contribution during migration repair.
     */
    public function reverseImportedContributionForMigrationRepair(Contribution $contribution): void
    {
        if (
            ! in_array($contribution->payment_method, [
                Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
                Contribution::PAYMENT_METHOD_IMPORT_CSV,
            ], true)
        ) {
            throw new InvalidArgumentException(__('Only legacy-import contributions can be reversed by migration repair.'));
        }

        $this->reverseAndDeleteImportedContributionForMigrationRepair($contribution);
    }

    /**
     * Remove a legacy-import contribution by deleting each ledger line with balance reconciliation.
     *
     * Do not call {@see reverseContributionPrincipal()} here — it credits member cash and
     * {@see reverseAndDeleteContribution()} then hard-deletes rows without adjusting balances,
     * which strands cash on the account (legacy migration repair drift).
     */
    private function reverseAndDeleteImportedContributionForMigrationRepair(Contribution $contribution): void
    {
        AccountingService::withoutMemberCashCollection(function () use ($contribution): void {
            DB::transaction(function () use ($contribution): void {
                $transactions = $contribution->transactions()->orderByDesc('id')->get();

                foreach ($transactions as $transaction) {
                    $this->accounting->deleteTransaction($transaction);
                }

                $contribution->forceDelete();
            });
        });
    }

    private function reverseAndDeleteContribution(Contribution $contribution, bool $forceDelete = false): void
    {
        AccountingService::withoutMemberCashCollection(function () use ($contribution, $forceDelete): void {
            DB::transaction(function () use ($contribution, $forceDelete): void {
                $contribution->loadMissing('member');

                if ($contribution->status === 'posted') {
                    $this->accounting->reverseContributionPrincipal(
                        $contribution,
                        (float) $contribution->amount,
                    );
                }

                $lateFeeCollected = $this->accounting->contributionLateFeeCollectedAmount($contribution);

                if ($lateFeeCollected > 0.00001) {
                    $this->accounting->reverseContributionLateFee($contribution, $lateFeeCollected);
                }

                $contribution->transactions()->delete();

                if ($forceDelete) {
                    $contribution->forceDelete();
                } else {
                    $contribution->delete();
                }
            });
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateAdminContribution(Contribution $contribution, array $data): Contribution
    {
        if (! $contribution->isEditableByAdmin()) {
            throw new InvalidArgumentException(__('Cycle and import contributions cannot be edited. Use reconciliation or reversal tools to correct ledger entries.'));
        }

        if ($contribution->isCoreEditableByAdmin()) {
            $memberId = (int) ($data['member_id'] ?? $contribution->member_id);
            [$month, $year] = Contribution::monthYearFromPeriod((string) ($data['period'] ?? $contribution->period));
            $periodDate = Contribution::periodDate($month, $year);

            $duplicateExists = Contribution::query()
                ->where('member_id', $memberId)
                ->where('period', $periodDate)
                ->whereKeyNot($contribution->id)
                ->exists();

            if ($duplicateExists) {
                throw ValidationException::withMessages([
                    'period' => [
                        __('A contribution already exists for :period.', [
                            'period' => $this->periodLabel($month, $year),
                        ]),
                    ],
                ]);
            }

            $amount = (float) ($data['amount'] ?? $contribution->amount);

            $contribution->update([
                'member_id' => $memberId,
                'period' => $periodDate,
                'amount' => $amount,
                'amount_due' => $amount,
                'reference_number' => $data['reference_number'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            return $contribution->fresh();
        }

        $contribution->update([
            'reference_number' => $data['reference_number'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return $contribution->fresh();
    }
}
