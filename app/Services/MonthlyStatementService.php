<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FeeDeduction;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\MonthlyStatement;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use App\Notifications\Tenant\MonthlyStatementNotification;
use App\Services\Tenant\MemberMembershipProfileService;
use App\Support\BusinessDay;
use App\Support\PublicPageSettings;
use App\Support\TransactionBusinessTypeCatalog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class MonthlyStatementService
{
    public function __construct(
        private readonly ContributionCycleService $cycles,
    ) {}

    public function generateForAllMembers(string $period, bool $notify = false): int
    {
        $generated = 0;

        Member::active()
            ->with(['user', 'accounts'])
            ->each(function (Member $member) use ($period, $notify, &$generated): void {
                try {
                    $this->generateForMember($member, $period, $notify);
                    $generated++;
                } catch (\Throwable $e) {
                    Log::error("MonthlyStatementService: failed for member {$member->id} period {$period}: ".$e->getMessage());
                }
            });

        return $generated;
    }

    public function generateForMember(Member $member, string $period, bool $notify = false): MonthlyStatement
    {
        [$year, $month] = array_map('intval', explode('-', $period));

        $details = $this->buildDetails($member, $period, $month, $year);

        $statement = MonthlyStatement::upsertForMember($member->id, $period, [
            'opening_balance' => $details['opening_balance'],
            'total_contributions' => $details['total_contributions'],
            'total_repayments' => $details['total_repayments'],
            'closing_balance' => $details['closing_balance'],
            'generated_at' => BusinessDay::now(),
            'details' => $details,
            'notified_at' => null,
        ]);

        if ($notify) {
            $this->sendNotification($statement);
        }

        return $statement;
    }

    public function sendNotification(
        MonthlyStatement $statement,
        string $delivery = MonthlyStatementNotification::DELIVERY_DEFAULT,
    ): bool {
        $statement->load('member.user');
        $user = $statement->member?->user;

        if ($user === null) {
            return false;
        }

        $notification = new MonthlyStatementNotification($statement, $delivery);

        if ($notification->via($user) === []) {
            return false;
        }

        try {
            $user->notify($notification);
            $statement->update(['notified_at' => BusinessDay::now()]);

            return true;
        } catch (\Throwable $e) {
            Log::error("MonthlyStatementService: notification failed for statement {$statement->id}: ".$e->getMessage());

            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function buildDetails(Member $member, string $period, int $month, int $year): array
    {
        $asOf = BusinessDay::now();
        $asOfEnd = $asOf->copy()->endOfDay();
        $periodStart = Carbon::create($year, $month, 1)->startOfDay();
        $periodEnd = $this->observationEnd(
            (clone $periodStart)->endOfMonth(),
            $asOfEnd,
        );

        $openingCutoff = $periodStart->copy()->subSecond();
        $openingCash = $this->balanceAtDate($member, 'cash', $openingCutoff);
        $openingFund = $this->balanceAtDate($member, 'fund', $openingCutoff);

        $periodDate = Contribution::periodDate($month, $year);
        $membershipStart = $member->joined_at?->copy()->startOfDay() ?? $periodStart->copy();

        $periodContribs = Contribution::query()
            ->where('member_id', $member->id)
            ->where('period', $periodDate)
            ->where('status', 'posted')
            ->where(function ($query) use ($asOfEnd): void {
                $query->whereNull('paid_at')
                    ->orWhere('paid_at', '<=', $asOfEnd);
            })
            ->get();

        $totalContributions = (float) $periodContribs->sum('amount');

        $paidInstallments = LoanInstallment::query()
            ->whereHas('loan', fn ($q) => $q->where('member_id', $member->id))
            ->whereBetween('paid_at', [$periodStart, $periodEnd])
            ->where('status', 'paid')
            ->with('loan:id')
            ->orderBy('paid_at')
            ->get();

        $totalRepayments = (float) $paidInstallments->sum(fn (LoanInstallment $i): float => (float) $i->amount + (float) ($i->late_fee_amount ?? 0));

        $memberAccountIds = Account::query()
            ->where('member_id', $member->id)
            ->whereIn('type', ['cash', 'fund'])
            ->pluck('id');

        $periodTransactions = Transaction::query()
            ->whereIn('account_id', $memberAccountIds)
            ->whereBetween('transacted_at', [$periodStart, $periodEnd])
            ->with('account')
            ->orderBy('transacted_at')
            ->get()
            ->map(fn (Transaction $tx): array => [
                'date' => $tx->transacted_at?->toDateTimeString(),
                'description' => $tx->description,
                'type' => $tx->type,
                'amount' => (float) $tx->amount,
                'account_type' => $tx->account?->type ?? 'unknown',
                'business_type' => TransactionBusinessTypeCatalog::keyFor($tx),
            ])
            ->all();

        $cashAtEnd = $this->balanceAtDate($member, 'cash', $periodEnd);
        $fundAtEnd = $this->balanceAtDate($member, 'fund', $periodEnd);

        $profile = app(MemberMembershipProfileService::class)->findForMember($member);
        $allLoans = $this->loanSummaries($member);
        $yearlyHistory = $this->yearlyHistory($member, $membershipStart, $year, $month, $asOfEnd);
        $currentYearMonths = $this->activityMonths($member, $year, $month, $asOfEnd, window: 6);

        [$closingCycleMonth, $closingCycleYear] = $this->activityThroughCycle($year, $month, $asOfEnd);
        $closingCycleEnd = $this->observationEnd(
            $this->cycles->cycleDueEndAt($closingCycleMonth, $closingCycleYear),
            $asOfEnd,
        );
        $lifetime = $this->lifetimeStats(
            $member,
            $closingCycleEnd,
            $closingCycleMonth,
            $closingCycleYear,
            $this->balanceAtDate($member, 'cash', $closingCycleEnd),
            $this->balanceAtDate($member, 'fund', $closingCycleEnd),
            $allLoans,
            $profile,
        );
        $fees = $this->feeBreakdown($member, $asOfEnd);

        $currency = Setting::get('general', 'currency', 'USD');

        $yearContribTotal = (float) collect($currentYearMonths)->sum('contributions');
        $yearRepayTotal = (float) collect($currentYearMonths)->sum('repayments');
        $maxMonthActivity = max(1.0, (float) collect($currentYearMonths)->max(
            fn (array $row): float => max((float) $row['contributions'], (float) $row['repayments']),
        ));
        $activityFrom = $currentYearMonths[0] ?? null;
        $activityTo = $currentYearMonths[array_key_last($currentYearMonths)] ?? null;

        return [
            'opening_balance' => $openingFund,
            'total_contributions' => $totalContributions,
            'total_repayments' => $totalRepayments,
            'closing_balance' => $fundAtEnd,
            'period' => $period,
            'period_label' => Carbon::create($year, $month, 1)->format('Y-m'),
            'currency' => $currency,
            'generated_at' => $asOf->toDateTimeString(),
            'as_of' => $asOf->toDateString(),
            'cash_opening' => $openingCash,
            'fund_opening' => $openingFund,
            'cash_closing' => $cashAtEnd,
            'fund_closing' => $fundAtEnd,
            'fund_name_en' => PublicPageSettings::fundName(locale: 'en'),
            'fund_name_ar' => PublicPageSettings::fundName(locale: 'ar'),
            'contributions' => $periodContribs->map(fn (Contribution $c): array => [
                'amount' => (float) $c->amount,
                'paid_at' => $c->paid_at?->toDateString(),
                'method' => $c->payment_method,
                'is_late' => (bool) $c->is_late,
                'late_fee_amount' => (float) ($c->late_fee_amount ?? 0),
            ])->all(),
            'period_installments' => $paidInstallments->map(fn (LoanInstallment $i): array => [
                'loan_id' => (int) $i->loan_id,
                'installment_number' => $i->installment_number,
                'due_date' => $i->due_date?->toDateString(),
                'paid_at' => $i->paid_at?->toDateString(),
                'amount' => (float) $i->amount,
                'late_fee_amount' => (float) ($i->late_fee_amount ?? 0),
            ])->all(),
            'period_transactions' => $periodTransactions,
            'loans' => $allLoans,
            'active_loan' => collect($allLoans)->first(fn (array $loan): bool => in_array($loan['status'], ['active', 'partially_disbursed'], true)),
            'yearly_history' => $yearlyHistory,
            'current_year_months' => $currentYearMonths,
            'current_year_totals' => [
                'year' => $year,
                'month_count' => count($currentYearMonths),
                'cycle_count' => count($currentYearMonths),
                'from_period' => $activityFrom['period'] ?? null,
                'to_period' => $activityTo['period'] ?? null,
                'from_year' => $activityFrom['year'] ?? null,
                'from_month' => $activityFrom['month'] ?? null,
                'to_year' => $activityTo['year'] ?? null,
                'to_month' => $activityTo['month'] ?? null,
                'from_label' => $activityFrom['label'] ?? null,
                'to_label' => $activityTo['label'] ?? null,
                'contributions' => round($yearContribTotal, 2),
                'repayments' => round($yearRepayTotal, 2),
                'max_activity' => round($maxMonthActivity, 2),
            ],
            'lifetime' => $lifetime,
            'fees' => $fees,
            'member_snapshot' => $this->memberSnapshot($member, $profile),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function memberSnapshot(Member $member, ?MembershipApplication $profile): array
    {
        return [
            'name' => $member->name,
            'member_number' => $member->member_number,
            'email' => $member->email,
            'phone' => $member->phone,
            'home_phone' => $profile?->home_phone,
            'work_phone' => $profile?->work_phone,
            'mobile_phone' => $profile?->mobile_phone ?: $member->phone,
            'iban' => $profile?->iban,
            'bank_account_number' => $profile?->bank_account_number,
            'status' => $member->status,
            'joined_at' => $member->joined_at?->toDateString(),
            'monthly_contrib' => (float) $member->monthly_contribution_amount,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loanSummaries(Member $member): array
    {
        $loans = Loan::query()
            ->where('member_id', $member->id)
            ->whereNotIn('status', ['cancelled', 'rejected', 'draft'])
            ->orderByDesc('disbursed_at')
            ->orderByDesc('id')
            ->with(['loanTier', 'installments'])
            ->get();

        return $loans->map(function (Loan $loan): array {
            $installments = $loan->installments;
            $paid = $installments->where('status', 'paid');
            $emi = (float) ($installments->sortBy('installment_number')->first()?->amount ?? 0);
            $total = $installments->count();
            $paidCount = $paid->count();

            return [
                'id' => (int) $loan->id,
                'status' => $loan->status,
                'amount_approved' => (float) ($loan->amount_approved ?? $loan->amount_requested ?? 0),
                'amount_disbursed' => (float) ($loan->amount_disbursed ?? 0),
                'emi_amount' => $emi,
                'tier' => $loan->loanTier?->label,
                'disbursed_at' => $loan->disbursed_at?->toDateString(),
                'approved_at' => $loan->approved_at?->toDateString(),
                'settled_at' => $loan->settled_at?->toDateString(),
                'installments_total' => $total,
                'installments_paid' => $paidCount,
                'repay_percent' => $total > 0 ? (int) round(($paidCount / $total) * 100) : 0,
                'outstanding' => (float) $installments
                    ->whereIn('status', ['pending', 'overdue'])
                    ->sum(fn (LoanInstallment $i): float => max(0, (float) $i->amount - (float) ($i->amount_collected ?? 0))),
            ];
        })->values()->all();
    }

    /**
     * Year rows are bounded by the Jan cycle start through the Dec cycle end (or the statement
     * cycle end when the statement cuts the year short). Labelled contribution periods remain
     * YYYY-MM-01 for Jan–Dec; paid_at / repayments / balances use the cycle window.
     *
     * @return list<array{year: int, contributions: float, repayments: float, cash_balance: float, fund_balance: float, through: ?string, cycle_start: string, cycle_end: string}>
     */
    private function yearlyHistory(Member $member, Carbon $membershipStart, int $statementYear, int $statementMonth, Carbon $asOfEnd): array
    {
        [$throughMonth, $throughYear] = $this->activityThroughCycle($statementYear, $statementMonth, $asOfEnd);
        $startYear = (int) $membershipStart->year;
        $rows = [];

        for ($year = $startYear; $year <= $throughYear; $year++) {
            $labelledYearStart = Carbon::create($year, 1, 1)->startOfDay();
            $labelledPeriodEnd = $year === $throughYear
                ? Carbon::create($throughYear, $throughMonth, 1)->startOfDay()
                : Carbon::create($year, 12, 1)->startOfDay();

            $yearCycleStart = $this->cycles->cycleStartAt(1, $year);
            $fullYearCycleEnd = $this->cycles->cycleDueEndAt(12, $year);
            $yearEnd = $year === $throughYear
                ? $this->observationEnd($this->cycles->cycleDueEndAt($throughMonth, $throughYear), $asOfEnd)
                : $fullYearCycleEnd->copy();

            // Contribution periods are stored as month-start dates (YYYY-MM-01). Clamping to the
            // join *day* would drop the join month (e.g. joined 2024-07-30 excludes period 2024-07-01).
            $contributionPeriodStart = $labelledYearStart->copy();
            if ($contributionPeriodStart->lt($membershipStart)) {
                $contributionPeriodStart = $membershipStart->copy()->startOfMonth();
            }

            $activityStart = $yearCycleStart->copy();
            if ($activityStart->lt($membershipStart)) {
                $activityStart = $membershipStart->copy();
            }

            if ($yearEnd->lt($contributionPeriodStart) && $yearEnd->lt($activityStart)) {
                continue;
            }

            // Align with year-end fund balance: only count amounts posted by Dec-cycle end (not
            // merely statement as-of). Periods paid after the Dec cycle spill into the next year.
            $paidBy = $this->observationEnd($yearEnd, $asOfEnd);
            $spilloverStart = $activityStart->copy()->lt($yearCycleStart) ? $yearCycleStart->copy() : $activityStart->copy();

            $contrib = (float) Contribution::query()
                ->where('member_id', $member->id)
                ->where('status', 'posted')
                ->where(function ($query) use ($contributionPeriodStart, $labelledPeriodEnd, $paidBy, $labelledYearStart, $spilloverStart): void {
                    $query->where(function ($q) use ($contributionPeriodStart, $labelledPeriodEnd, $paidBy): void {
                        $q->whereBetween('period', [$contributionPeriodStart->toDateString(), $labelledPeriodEnd->toDateString()])
                            ->where(function ($paid) use ($paidBy): void {
                                $paid->whereNull('paid_at')
                                    ->orWhere('paid_at', '<=', $paidBy);
                            });
                    })->orWhere(function ($q) use ($labelledYearStart, $spilloverStart, $paidBy): void {
                        $q->where('period', '<', $labelledYearStart->toDateString())
                            ->whereNotNull('paid_at')
                            ->whereBetween('paid_at', [$spilloverStart, $paidBy]);
                    });
                })
                ->sum('amount');

            $repay = (float) LoanInstallment::query()
                ->whereHas('loan', fn ($q) => $q->where('member_id', $member->id))
                ->where('status', 'paid')
                ->whereBetween('paid_at', [$activityStart, $yearEnd])
                ->sum('amount');

            $rows[] = [
                'year' => $year,
                'contributions' => round($contrib, 2),
                'repayments' => round($repay, 2),
                'cash_balance' => $this->balanceAtDate($member, 'cash', $yearEnd),
                'fund_balance' => $this->balanceAtDate($member, 'fund', $yearEnd),
                // Display bounds are always Jan-cycle → Dec/statement-cycle (not membership join day).
                'cycle_start' => $yearCycleStart->toDateString(),
                'cycle_end' => $yearEnd->toDateString(),
                // Present when the statement period cuts the Jan–Dec cycle year short.
                'through' => $yearEnd->lt($fullYearCycleEnd) ? $yearEnd->toDateString() : null,
            ];
        }

        return $rows;
    }

    /**
     * Rolling window of contribution-cycle activity ending at the statement cycle (or the
     * business-day cycle when that is earlier).
     *
     * Each bucket uses the cycle start/end window. Contributions are bucketed by payment /
     * fund-ledger date (not contribution period), so a payment that falls in the next cycle
     * appears under that cycle — matching the fund ledger the member sees.
     *
     * @return list<array{month: int, year: int, period: string, label: string, cycle_start: string, cycle_end: string, contributions: float, repayments: float, contribution_dates: list<string>, repayment_dates: list<string>}>
     */
    private function activityMonths(Member $member, int $year, int $throughMonth, Carbon $asOfEnd, int $window = 6): array
    {
        [$endMonth, $endYear] = $this->activityThroughCycle($year, $throughMonth, $asOfEnd);
        $end = Carbon::create($endYear, $endMonth, 1)->startOfMonth();
        $start = $end->copy()->subMonthsNoOverflow(max(1, $window) - 1);
        $rows = [];

        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addMonthNoOverflow()) {
            $month = (int) $cursor->month;
            $rowYear = (int) $cursor->year;
            $cycleStart = $this->cycles->cycleStartAt($month, $rowYear);
            $cycleEnd = $this->observationEnd($this->cycles->cycleDueEndAt($month, $rowYear), $asOfEnd);

            $contribs = Contribution::query()
                ->where('member_id', $member->id)
                ->where('status', 'posted')
                ->whereNotNull('paid_at')
                ->whereBetween('paid_at', [$cycleStart, $cycleEnd])
                ->get(['amount', 'paid_at']);

            $partialCredits = $this->unpostedContributionFundCredits($member, $cycleStart, $cycleEnd);

            $repayments = LoanInstallment::query()
                ->whereHas('loan', fn ($q) => $q->where('member_id', $member->id))
                ->where('status', 'paid')
                ->whereBetween('paid_at', [$cycleStart, $cycleEnd])
                ->get(['amount', 'paid_at', 'late_fee_amount']);

            $contributionDates = $contribs
                ->map(fn (Contribution $c): ?string => $c->paid_at?->toDateString())
                ->filter()
                ->values()
                ->all();

            foreach ($partialCredits['dates'] as $date) {
                $contributionDates[] = $date;
            }

            $rows[] = [
                'month' => $month,
                'year' => $rowYear,
                'period' => sprintf('%04d-%02d', $rowYear, $month),
                'label' => $this->cycleName($month, $rowYear),
                'cycle_start' => $cycleStart->toDateString(),
                'cycle_end' => $cycleEnd->toDateString(),
                'contributions' => round((float) $contribs->sum('amount') + $partialCredits['amount'], 2),
                'repayments' => round((float) $repayments->sum(
                    fn (LoanInstallment $i): float => (float) $i->amount + (float) ($i->late_fee_amount ?? 0),
                ), 2),
                'contribution_dates' => collect($contributionDates)->unique()->sort()->values()->all(),
                'repayment_dates' => $repayments
                    ->map(fn (LoanInstallment $i): ?string => $i->paid_at?->toDateString())
                    ->filter()
                    ->values()
                    ->all(),
            ];
        }

        return $rows;
    }

    /**
     * @return array{0: int, 1: int} month, year of the latest cycle included in activity / yearly through
     */
    private function activityThroughCycle(int $statementYear, int $statementMonth, Carbon $asOfEnd): array
    {
        [$asOfMonth, $asOfYear] = $this->cycles->cyclePeriodForDueDate($asOfEnd);
        $statementCursor = Carbon::create($statementYear, $statementMonth, 1)->startOfMonth();
        $asOfCursor = Carbon::create($asOfYear, $asOfMonth, 1)->startOfMonth();
        $through = $statementCursor->gt($asOfCursor) ? $asOfCursor : $statementCursor;

        return [(int) $through->month, (int) $through->year];
    }

    private function cycleName(int $month, int $year): string
    {
        $monthLabel = Carbon::create($year, $month, 1)
            ->locale(app()->getLocale())
            ->translatedFormat('M');

        return __(':month Cycle :year', [
            'month' => $monthLabel,
            'year' => $year,
        ]);
    }

    /**
     * Lifetime KPIs through the statement closing cycle end (not calendar month-end or generation day).
     *
     * @param  list<array<string, mixed>>  $loans
     * @return array<string, mixed>
     */
    private function lifetimeStats(
        Member $member,
        Carbon $closingCycleEnd,
        int $closingCycleMonth,
        int $closingCycleYear,
        float $cashBalance,
        float $fundBalance,
        array $loans,
        ?MembershipApplication $profile,
    ): array {
        $labelledPeriodEnd = Carbon::create($closingCycleYear, $closingCycleMonth, 1)->toDateString();

        $lifetimeContributions = (float) Contribution::query()
            ->where('member_id', $member->id)
            ->where('status', 'posted')
            ->where('period', '<=', $labelledPeriodEnd)
            ->where(function ($query) use ($closingCycleEnd): void {
                $query->whereNull('paid_at')
                    ->orWhere('paid_at', '<=', $closingCycleEnd);
            })
            ->sum('amount');

        // Open-cycle / partial collections hit the fund before status becomes posted.
        $lifetimeContributions += $this->unpostedContributionFundCredits(
            $member,
            Carbon::parse('1970-01-01')->startOfDay(),
            $closingCycleEnd,
        )['amount'];

        $lifetimeRepayments = (float) LoanRepayment::query()
            ->whereHas('loan', fn ($q) => $q->where('member_id', $member->id))
            ->where('paid_at', '<=', $closingCycleEnd)
            ->sum('amount');

        $loanCount = count($loans);
        $loanAmount = (float) collect($loans)->sum('amount_approved');
        $lifetimeContributions = round($lifetimeContributions, 2);
        $lifetimeRepayments = round($lifetimeRepayments, 2);

        return [
            'as_of' => $closingCycleEnd->toDateString(),
            'joined_at' => $member->joined_at?->toDateString(),
            'membership_years' => $member->joined_at
                ? max(0, (int) $member->joined_at->diffInYears($closingCycleEnd))
                : 0,
            'total_contributions' => $lifetimeContributions,
            'total_repayments' => $lifetimeRepayments,
            'collection_total' => round($lifetimeContributions + $lifetimeRepayments, 2),
            'loan_count' => $loanCount,
            'loan_amount' => round($loanAmount, 2),
            'cash_balance' => round($cashBalance, 2),
            'fund_balance' => round($fundBalance, 2),
            'monthly_contribution' => (float) $member->monthly_contribution_amount,
            'iban' => $profile?->iban,
        ];
    }

    /**
     * Fund credits posted against contributions that are not fully posted yet (partial open-cycle).
     *
     * @return array{amount: float, dates: list<string>}
     */
    private function unpostedContributionFundCredits(Member $member, Carbon $from, Carbon $to): array
    {
        $fundAccountId = Account::query()
            ->where('member_id', $member->id)
            ->where('type', 'fund')
            ->value('id');

        if ($fundAccountId === null) {
            return ['amount' => 0.0, 'dates' => []];
        }

        $unpostedIds = Contribution::query()
            ->where('member_id', $member->id)
            ->where('status', '!=', 'posted')
            ->pluck('id');

        if ($unpostedIds->isEmpty()) {
            return ['amount' => 0.0, 'dates' => []];
        }

        $credits = Transaction::query()
            ->where('account_id', $fundAccountId)
            ->where('type', 'credit')
            ->where('reference_type', Contribution::class)
            ->whereIn('reference_id', $unpostedIds)
            ->whereBetween('transacted_at', [$from, $to])
            ->get(['amount', 'transacted_at']);

        return [
            'amount' => (float) $credits->sum('amount'),
            'dates' => $credits
                ->map(fn (Transaction $tx): ?string => $tx->transacted_at?->toDateString())
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{total: float, groups: list<array{key: string, label_key: string, amount: float}>}
     */
    private function feeBreakdown(Member $member, Carbon $asOfEnd): array
    {
        $contributionLateFees = (float) Contribution::query()
            ->where('member_id', $member->id)
            ->where('status', 'posted')
            ->where('late_fee_amount', '>', 0)
            ->where(function ($query) use ($asOfEnd): void {
                $query->whereNull('paid_at')
                    ->orWhere('paid_at', '<=', $asOfEnd);
            })
            ->sum('late_fee_amount');

        $repaymentLateFees = (float) LoanInstallment::query()
            ->whereHas('loan', fn ($q) => $q->where('member_id', $member->id))
            ->where('late_fee_amount', '>', 0)
            ->where(function ($query) use ($asOfEnd): void {
                $query->where('paid_at', '<=', $asOfEnd)
                    ->orWhere(function ($inner) use ($asOfEnd): void {
                        $inner->whereNull('paid_at')
                            ->where('due_date', '<=', $asOfEnd->toDateString());
                    });
            })
            ->sum('late_fee_amount');

        $manualFees = (float) FeeDeduction::query()
            ->where('member_id', $member->id)
            ->where('transacted_at', '<=', $asOfEnd)
            ->sum('amount');

        $subscriptionFees = (float) MembershipApplication::query()
            ->where('member_id', $member->id)
            ->where('status', 'approved')
            ->where(function ($query) use ($asOfEnd): void {
                $query->where('reviewed_at', '<=', $asOfEnd)
                    ->orWhere(function ($inner) use ($asOfEnd): void {
                        $inner->whereNull('reviewed_at')
                            ->where('membership_date', '<=', $asOfEnd->toDateString());
                    });
            })
            ->sum('membership_fee_amount');

        $groups = [
            ['key' => 'contribution_late', 'label_key' => 'Contribution late fees', 'amount' => round($contributionLateFees, 2)],
            ['key' => 'repayment_late', 'label_key' => 'Repayment late fees', 'amount' => round($repaymentLateFees, 2)],
            ['key' => 'subscription', 'label_key' => 'Subscription fees', 'amount' => round($subscriptionFees, 2)],
            ['key' => 'other', 'label_key' => 'Other fees', 'amount' => round($manualFees, 2)],
        ];

        $groups = array_values(array_filter($groups, fn (array $g): bool => $g['amount'] > 0.004));

        return [
            'total' => round(array_sum(array_column($groups, 'amount')), 2),
            'groups' => $groups,
        ];
    }

    private function observationEnd(Carbon $candidateEnd, Carbon $asOfEnd): Carbon
    {
        return $candidateEnd->gt($asOfEnd) ? $asOfEnd->copy() : $candidateEnd->copy();
    }

    private function balanceAtDate(Member $member, string $accountType, Carbon $date): float
    {
        $accountId = Account::query()
            ->where('member_id', $member->id)
            ->where('type', $accountType)
            ->value('id');

        if ($accountId === null) {
            return 0.0;
        }

        $asOf = $date->copy()->endOfDay();

        $credits = (float) Transaction::query()
            ->where('account_id', $accountId)
            ->where('type', 'credit')
            ->where('transacted_at', '<=', $asOf)
            ->sum('amount');

        $debits = (float) Transaction::query()
            ->where('account_id', $accountId)
            ->where('type', 'debit')
            ->where('transacted_at', '<=', $asOf)
            ->sum('amount');

        return round($credits - $debits, 2);
    }
}
