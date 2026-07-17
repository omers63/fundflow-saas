<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FeeDeduction;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
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

    public function sendNotification(MonthlyStatement $statement): void
    {
        $statement->load('member.user');
        $user = $statement->member?->user;

        if ($user === null) {
            return;
        }

        try {
            $user->notify(new MonthlyStatementNotification($statement));
            $statement->update(['notified_at' => BusinessDay::now()]);
        } catch (\Throwable $e) {
            Log::error("MonthlyStatementService: notification failed for statement {$statement->id}: ".$e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function buildDetails(Member $member, string $period, int $month, int $year): array
    {
        $lastStatement = MonthlyStatement::query()
            ->where('member_id', $member->id)
            ->where('period', '<', $period)
            ->orderByDesc('period')
            ->first();

        $openingCash = (float) ($lastStatement?->details['cash_closing'] ?? $member->getCashBalance());
        $openingFund = (float) ($lastStatement?->details['fund_closing'] ?? $member->getFundBalance());
        $opening = (float) ($lastStatement?->closing_balance ?? 0);

        $periodDate = Contribution::periodDate($month, $year);
        $periodStart = Carbon::create($year, $month, 1)->startOfDay();
        $periodEnd = (clone $periodStart)->endOfMonth();
        $asOf = BusinessDay::now();
        $membershipStart = $member->joined_at?->copy()->startOfDay() ?? $periodStart->copy();

        $periodContribs = Contribution::query()
            ->where('member_id', $member->id)
            ->where('period', $periodDate)
            ->where('status', 'posted')
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
        $yearlyHistory = $this->yearlyHistory($member, $membershipStart, $year, $month);
        $currentYearMonths = $this->currentYearMonths($member, $year, $month);
        $lifetime = $this->lifetimeStats($member, $asOf, $cashAtEnd, $fundAtEnd, $allLoans, $profile);
        $fees = $this->feeBreakdown($member, $asOf);

        $closing = $opening + $totalContributions - $totalRepayments;
        $currency = Setting::get('general', 'currency', 'USD');

        $yearContribTotal = (float) collect($currentYearMonths)->sum('contributions');
        $yearRepayTotal = (float) collect($currentYearMonths)->sum('repayments');
        $maxMonthActivity = max(1.0, (float) collect($currentYearMonths)->max(
            fn (array $row): float => max((float) $row['contributions'], (float) $row['repayments']),
        ));

        return [
            'opening_balance' => $opening,
            'total_contributions' => $totalContributions,
            'total_repayments' => $totalRepayments,
            'closing_balance' => $closing,
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
     * @return list<array{year: int, contributions: float, repayments: float}>
     */
    private function yearlyHistory(Member $member, Carbon $membershipStart, int $statementYear, int $statementMonth): array
    {
        $end = Carbon::create($statementYear, $statementMonth, 1)->endOfMonth();
        $startYear = (int) $membershipStart->year;
        $rows = [];

        for ($year = $startYear; $year <= $statementYear; $year++) {
            $yearStart = Carbon::create($year, 1, 1)->startOfDay();
            $yearEnd = $year === $statementYear
                ? $end->copy()
                : Carbon::create($year, 12, 31)->endOfDay();

            if ($yearStart->lt($membershipStart)) {
                $yearStart = $membershipStart->copy();
            }

            $contrib = (float) Contribution::query()
                ->where('member_id', $member->id)
                ->where('status', 'posted')
                ->whereBetween('period', [$yearStart->toDateString(), $yearEnd->toDateString()])
                ->sum('amount');

            $repay = (float) LoanInstallment::query()
                ->whereHas('loan', fn ($q) => $q->where('member_id', $member->id))
                ->where('status', 'paid')
                ->whereBetween('paid_at', [$yearStart, $yearEnd])
                ->sum('amount');

            $rows[] = [
                'year' => $year,
                'contributions' => round($contrib, 2),
                'repayments' => round($repay, 2),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{month: int, label_key: string, contributions: float, repayments: float, contribution_dates: list<string>, repayment_dates: list<string>}>
     */
    private function currentYearMonths(Member $member, int $year, int $throughMonth): array
    {
        $rows = [];

        for ($month = 1; $month <= $throughMonth; $month++) {
            $periodDate = Contribution::periodDate($month, $year);
            $start = Carbon::create($year, $month, 1)->startOfDay();
            $end = (clone $start)->endOfMonth();

            $contribs = Contribution::query()
                ->where('member_id', $member->id)
                ->where('period', $periodDate)
                ->where('status', 'posted')
                ->get(['amount', 'paid_at']);

            $repayments = LoanInstallment::query()
                ->whereHas('loan', fn ($q) => $q->where('member_id', $member->id))
                ->where('status', 'paid')
                ->whereBetween('paid_at', [$start, $end])
                ->get(['amount', 'paid_at', 'late_fee_amount']);

            $rows[] = [
                'month' => $month,
                'period' => sprintf('%04d-%02d', $year, $month),
                'contributions' => round((float) $contribs->sum('amount'), 2),
                'repayments' => round((float) $repayments->sum(
                    fn (LoanInstallment $i): float => (float) $i->amount + (float) ($i->late_fee_amount ?? 0),
                ), 2),
                'contribution_dates' => $contribs
                    ->map(fn (Contribution $c): ?string => $c->paid_at?->toDateString())
                    ->filter()
                    ->values()
                    ->all(),
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
     * @param  list<array<string, mixed>>  $loans
     * @return array<string, mixed>
     */
    private function lifetimeStats(
        Member $member,
        Carbon $asOf,
        float $cashBalance,
        float $fundBalance,
        array $loans,
        ?MembershipApplication $profile,
    ): array {
        $lifetimeContributions = (float) Contribution::query()
            ->where('member_id', $member->id)
            ->where('status', 'posted')
            ->sum('amount');

        $loanCount = count($loans);
        $loanAmount = (float) collect($loans)->sum('amount_approved');

        return [
            'as_of' => $asOf->toDateString(),
            'joined_at' => $member->joined_at?->toDateString(),
            'membership_years' => $member->joined_at
                ? max(0, (int) $member->joined_at->diffInYears($asOf))
                : 0,
            'total_contributions' => round($lifetimeContributions, 2),
            'loan_count' => $loanCount,
            'loan_amount' => round($loanAmount, 2),
            'cash_balance' => round($cashBalance, 2),
            'fund_balance' => round($fundBalance, 2),
            'monthly_contribution' => (float) $member->monthly_contribution_amount,
            'iban' => $profile?->iban,
        ];
    }

    /**
     * @return array{total: float, groups: list<array{key: string, label_key: string, amount: float}>}
     */
    private function feeBreakdown(Member $member, Carbon $asOf): array
    {
        $contributionLateFees = (float) Contribution::query()
            ->where('member_id', $member->id)
            ->where('status', 'posted')
            ->where('late_fee_amount', '>', 0)
            ->sum('late_fee_amount');

        $repaymentLateFees = (float) LoanInstallment::query()
            ->whereHas('loan', fn ($q) => $q->where('member_id', $member->id))
            ->where('late_fee_amount', '>', 0)
            ->sum('late_fee_amount');

        $manualFees = (float) FeeDeduction::query()
            ->where('member_id', $member->id)
            ->where('transacted_at', '<=', $asOf)
            ->sum('amount');

        $subscriptionFees = (float) MembershipApplication::query()
            ->where('member_id', $member->id)
            ->where('status', 'approved')
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

    private function balanceAtDate(Member $member, string $accountType, Carbon $date): float
    {
        $accountId = Account::query()
            ->where('member_id', $member->id)
            ->where('type', $accountType)
            ->value('id');

        if ($accountId === null) {
            return 0.0;
        }

        $credits = (float) Transaction::query()
            ->where('account_id', $accountId)
            ->where('type', 'credit')
            ->where('transacted_at', '<=', $date->copy()->endOfDay())
            ->sum('amount');

        $debits = (float) Transaction::query()
            ->where('account_id', $accountId)
            ->where('type', 'debit')
            ->where('transacted_at', '<=', $date->copy()->endOfDay())
            ->sum('amount');

        return round($credits - $debits, 2);
    }
}
