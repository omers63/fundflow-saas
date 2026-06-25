<?php

namespace App\Services\Loans;

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Notifications\Tenant\LoanRepaymentAppliedNotification;
use App\Notifications\Tenant\LoanRepaymentDueNotification;
use App\Services\ContributionCycleService;
use App\Support\BusinessDay;
use App\Support\DatabaseDialect;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LoanRepaymentService
{
    public function __construct(
        protected LoanLedgerService $ledger,
        protected LateFeeService $lateFees,
    ) {}

    // =========================================================================
    // Deadline helpers (mirrors ContributionCycleService)
    // =========================================================================

    public function deadline(int $month, int $year): Carbon
    {
        return app(ContributionCycleService::class)->deadline($month, $year);
    }

    public function isLate(int $month, int $year): bool
    {
        return BusinessDay::now()->greaterThan($this->deadline($month, $year));
    }

    public function periodLabel(int $month, int $year): string
    {
        return app(ContributionCycleService::class)->periodLabel($month, $year);
    }

    // =========================================================================
    // Due notifications (sent on 1st of month)
    // =========================================================================

    /**
     * Notify all active borrowers whose repayment for the given period is due.
     */
    public function sendDueNotifications(int $month, int $year): int
    {
        $deadline = $this->deadline($month, $year);
        $notified = 0;

        Loan::active()
            ->with(['member.user', 'installments'])
            ->each(function (Loan $loan) use ($month, $year, $deadline, &$notified) {
                // Find the installment due in this period
                $installment = $this->installmentForPeriod($loan, $month, $year);
                if (! $installment || $installment->isPaid()) {
                    return;
                }

                $cashBalance = (float) ($loan->member->cashAccount?->balance ?? 0);

                try {
                    $loan->member->user->notify(new LoanRepaymentDueNotification(
                        loan: $loan,
                        installment: $installment,
                        deadline: $deadline,
                        cashBalance: $cashBalance,
                    ));
                    $notified++;
                } catch (\Throwable $e) {
                    logger()->error('LoanRepaymentService: due notification failed', [
                        'loan_id' => $loan->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

        return $notified;
    }

    // =========================================================================
    // Apply repayments for a given period
    // =========================================================================

    /**
     * Apply loan repayments for all active loans for the given month/year period.
     *
     * Returns: ['applied' => Collection, 'insufficient' => Collection, 'skipped' => Collection]
     */
    public function applyRepayments(int $month, int $year): array
    {
        $results = [
            'applied' => collect(),
            'insufficient' => collect(),
            'skipped' => collect(),
        ];

        Loan::active()->with(['member.user', 'installments'])->each(
            function (Loan $loan) use ($month, $year, &$results) {
                $this->applyOne($loan, $month, $year, $results);
            }
        );

        return $results;
    }

    /**
     * Apply repayment for a single loan / period. Returns 'applied'|'insufficient'|'skipped'.
     */
    public function applyOne(Loan $loan, int $month, int $year, array &$results = []): string
    {
        $installment = $this->installmentForPeriod($loan, $month, $year);

        if (! $installment || $installment->isPaid()) {
            $results['skipped'][] = $loan;

            return 'skipped';
        }

        $cycles = app(ContributionCycleService::class);
        $periodStart = $cycles->cycleStartAt($month, $year);

        if ($periodStart->lt($loan->repaymentWindowOpensAt())) {
            $results['skipped'][] = $loan;

            return 'skipped';
        }

        $member = $loan->member;

        if (Contribution::blocksLoanRepaymentForMemberPeriod($member, $month, $year)) {
            logger()->warning('LoanRepaymentService: skipping scheduled repayment — contribution row exists for same cycle', [
                'loan_id' => $loan->id,
                'member_id' => $loan->member_id,
                'month' => $month,
                'year' => $year,
            ]);
            $results['skipped'][] = $loan;

            return 'skipped';
        }
        $amount = (float) $installment->amount;
        $deadline = $this->deadline($month, $year);
        $days = $this->lateFees->daysPastDue($deadline, BusinessDay::now());
        $lateFee = $this->lateFees->repaymentLateFeeForDays($days);
        $isLate = $days >= 1;
        $required = $amount + $lateFee;
        $cashAccount = Account::query()
            ->where('type', 'cash')
            ->where('member_id', $member->id)
            ->where('is_master', false)
            ->first();

        if (! $cashAccount || (float) $cashAccount->balance < $required) {
            $results['insufficient'][] = [
                'loan' => $loan,
                'balance' => (float) ($cashAccount?->balance ?? 0),
                'required' => $required,
            ];

            return 'insufficient';
        }

        DB::transaction(function () use ($loan, $installment, $member, $amount, $isLate, $lateFee) {
            // 1. Debit member's cash account (installment + late fee when applicable)
            $this->ledger->debitCashForRepayment($member, $installment, $lateFee);

            // 2. Mark installment paid (observer posts to fund accounts + updates repaid_to_master)
            $installment->update([
                'status' => 'paid',
                'paid_at' => BusinessDay::now(),
                'is_late' => $isLate,
                'late_fee_amount' => $lateFee > 0.00001 ? $lateFee : 0,
            ]);

            // 3. Track late stats
            if ($isLate) {
                $loan->increment('late_repayment_count');
                $loan->increment('late_repayment_amount', $amount);
                $member->increment('late_repayment_count');
                $member->increment('late_repayment_amount', $amount);
            }

            // 4. Send account statement
            try {
                $freshCash = Account::query()
                    ->where('type', 'cash')
                    ->where('member_id', $member->id)
                    ->where('is_master', false)
                    ->first();

                $loan->refresh();
                $member->user->notify(new LoanRepaymentAppliedNotification(
                    loan: $loan,
                    installment: $installment,
                    cashBalance: (float) ($freshCash?->balance ?? 0),
                    isLate: $isLate,
                ));
            } catch (\Throwable $e) {
                logger()->error('LoanRepaymentService: statement notification failed', [
                    'loan_id' => $loan->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        $results['applied'][] = $loan;

        return 'applied';
    }

    /**
     * Apply one known installment as an imported repayment with an explicit payment timestamp.
     *
     * @return 'applied'|'insufficient'|'skipped'
     */
    public function applyImportedInstallmentPayment(
        LoanInstallment $installment,
        CarbonInterface $paidAt,
        float $lateFee = 0.0,
    ): string {
        $installment->loadMissing('loan.member.user');
        $loan = $installment->loan;
        if (! $loan instanceof Loan || $installment->isPaid()) {
            return 'skipped';
        }

        $member = $loan->member;
        if (! $member instanceof Member) {
            return 'skipped';
        }

        $cashAccount = Account::query()
            ->where('type', 'cash')
            ->where('member_id', $member->id)
            ->where('is_master', false)
            ->first();
        $required = (float) $installment->amount + max(0.0, $lateFee);
        if (! $cashAccount || (float) $cashAccount->balance + 0.00001 < $required) {
            return 'insufficient';
        }

        DB::transaction(function () use ($member, $installment, $paidAt, $lateFee): void {
            $isLate = $lateFee > 0.00001;
            $this->ledger->debitCashForRepayment($member, $installment, $lateFee, $paidAt);
            $installment->update([
                'status' => 'paid',
                'paid_at' => $paidAt,
                'is_late' => $isLate,
                'late_fee_amount' => $isLate ? $lateFee : null,
            ]);
        });

        return 'applied';
    }

    // =========================================================================
    // Open period (aligned with ContributionCycleService::currentOpenPeriod)
    // =========================================================================

    /** Whether to show one-click repayment for the current open period (active loan, unpaid installment for that period). */
    public function shouldOfferOpenPeriodRepayment(Member $member): bool
    {
        if ($member->status !== 'active') {
            return false;
        }

        $loan = Loan::active()
            ->where('member_id', $member->id)
            ->first();

        if ($loan === null) {
            return false;
        }

        [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();
        $installment = $this->installmentForPeriod($loan, $month, $year);

        return $installment !== null && ! $installment->isPaid();
    }

    public function hasInsufficientCashForOpenPeriodRepayment(Member $member): bool
    {
        $loan = Loan::active()->where('member_id', $member->id)->first();
        if ($loan === null) {
            return true;
        }

        [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();
        $installment = $this->installmentForPeriod($loan, $month, $year);

        if ($installment === null || $installment->isPaid()) {
            return true;
        }

        $deadline = $this->deadline($month, $year);
        $days = $this->lateFees->daysPastDue($deadline, BusinessDay::now());
        $lateFee = $this->lateFees->repaymentLateFeeForDays($days);
        $required = (float) $installment->amount + $lateFee;

        return (float) $member->cash_balance < $required;
    }

    public function openPeriodRepaymentModalDescription(Member $member): string
    {
        $loan = Loan::active()->where('member_id', $member->id)->first();
        $amount = 0.0;
        $lateFee = 0.0;
        if ($loan !== null) {
            [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();
            $installment = $this->installmentForPeriod($loan, $month, $year);
            $amount = (float) ($installment?->amount ?? 0);
            $deadline = $this->deadline($month, $year);
            $days = $this->lateFees->daysPastDue($deadline, BusinessDay::now());
            $lateFee = $this->lateFees->repaymentLateFeeForDays($days);
        }

        $total = $amount + $lateFee;
        $period = app(ContributionCycleService::class)->currentOpenPeriodLabel();
        $base = __('Debits SAR :total from the member cash account (balance: SAR :balance) for loan repayment in :period.', [
            'total' => number_format($total, 2),
            'balance' => number_format((float) $member->cash_balance, 2),
            'period' => $period,
        ]);
        $note = $lateFee > 0.00001
            ? __('Master and member funds are credited the installment principal only; a late fee of SAR :fee is credited to master cash only.', [
                'fee' => number_format($lateFee, 2),
            ])
            : __('Deposits match the repayment run.');

        return $base.' '.$note;
    }

    /**
     * Human-readable reason when {@see applyOpenPeriodRepaymentForMember()} would return `skipped`.
     */
    public function openPeriodSkipMessage(Member $member): string
    {
        $cycles = app(ContributionCycleService::class);
        [$month, $year] = $cycles->currentOpenPeriod();
        $periodLabel = $cycles->periodLabel($month, $year);

        $loan = Loan::active()->where('member_id', $member->id)->first();
        if ($loan === null) {
            return __('No active loan for this member.');
        }

        $installment = $this->installmentForPeriod($loan, $month, $year);

        if ($installment === null || $installment->isPaid()) {
            $next = $loan->installments()
                ->whereIn('status', ['pending', 'overdue'])
                ->orderBy('due_date')
                ->first();

            if ($next !== null && ! $cycles->dueDateFallsInCycle($next->due_date, $month, $year)) {
                return __('No unpaid installment in the open period (:period). The next EMI is due :date (:next_period cycle).', [
                    'period' => $periodLabel,
                    'date' => $next->due_date->toFormattedDateString(),
                    'next_period' => $cycles->periodLabel(...$cycles->cyclePeriodForDueDate($next->due_date)),
                ]);
            }

            return __('No unpaid installment in the open period (:period).', [
                'period' => $periodLabel,
            ]);
        }

        if (Contribution::blocksLoanRepaymentForMemberPeriod($member, $month, $year)) {
            return __('Repayment for :period is skipped while an active contribution row exists for the same cycle.', [
                'period' => $periodLabel,
            ]);
        }

        return __('Could not apply repayment for :period.', [
            'period' => $periodLabel,
        ]);
    }

    /**
     * Apply the installment due in the current open period for this member's active loan.
     *
     * @return 'applied'|'insufficient'|'skipped'
     */
    public function applyOpenPeriodRepaymentForMember(Member $member): string
    {
        $member->unsetRelation('accounts');
        $member->load(['user', 'accounts']);

        $loan = Loan::active()->where('member_id', $member->id)->first();
        if ($loan === null) {
            return 'skipped';
        }

        [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();
        $bucket = [];

        return $this->applyOne($loan, $month, $year, $bucket);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Find the installment whose due_date falls within the given month/year period,
     * matching on first_repayment schedule.
     */
    public function installmentForPeriod(Loan $loan, int $month, int $year): ?LoanInstallment
    {
        $cycles = app(ContributionCycleService::class);
        [$start, $end] = $cycles->cycleDueDateBounds($month, $year);

        return $loan->installments()
            ->whereIn('status', ['pending', 'overdue'])
            ->whereBetween('due_date', [$start, $end])
            ->orderBy('due_date')
            ->first();
    }

    public function periodSummaries(int $limit = 12): Collection
    {
        $y = DatabaseDialect::yearExpression('due_date');
        $m = DatabaseDialect::monthExpression('due_date');

        return LoanInstallment::query()
            ->selectRaw("{$y} as year, {$m} as month,
                 COUNT(*) as total_count,
                 SUM(amount) as total_amount,
                 SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) as paid_count,
                 SUM(is_late) as late_count")
            ->groupByRaw("{$y}, {$m}")
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'period_label' => $this->periodLabel((int) $r->month, (int) $r->year),
                'month' => (int) $r->month,
                'year' => (int) $r->year,
                'total_count' => (int) $r->total_count,
                'total_amount' => (float) $r->total_amount,
                'paid_count' => (int) $r->paid_count,
                'late_count' => (int) $r->late_count,
                'deadline' => $this->deadline((int) $r->month, (int) $r->year)
                    ->locale(app()->getLocale())
                    ->translatedFormat('d M Y'),
            ]);
    }
}
