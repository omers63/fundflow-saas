<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\MonthlyStatement;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use App\Notifications\Tenant\MonthlyStatementNotification;
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
            'generated_at' => now(),
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
            $statement->update(['notified_at' => now()]);
        } catch (\Throwable $e) {
            Log::error("MonthlyStatementService: notification failed for statement {$statement->id}: ".$e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDetails(Member $member, string $period, int $month, int $year): array
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

        $periodContribs = Contribution::query()
            ->where('member_id', $member->id)
            ->where('period', $periodDate)
            ->where('status', 'posted')
            ->get();

        $totalContributions = (float) $periodContribs->sum('amount');

        $periodStart = Carbon::create($year, $month, 1)->startOfDay();
        $periodEnd = (clone $periodStart)->endOfMonth();

        $paidInstallments = LoanInstallment::query()
            ->whereHas('loan', fn ($q) => $q->where('member_id', $member->id))
            ->whereBetween('paid_at', [$periodStart, $periodEnd])
            ->where('status', 'paid')
            ->get();

        $totalRepayments = (float) $paidInstallments->sum('amount');

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
            ->map(fn (Transaction $tx) => [
                'date' => $tx->transacted_at?->toDateTimeString(),
                'description' => $tx->description,
                'type' => $tx->type,
                'amount' => (float) $tx->amount,
                'account_type' => $tx->account?->type ?? 'unknown',
            ])
            ->all();

        $cashAtEnd = $this->balanceAtDate($member, 'cash', $periodEnd);
        $fundAtEnd = $this->balanceAtDate($member, 'fund', $periodEnd);

        $activeLoan = Loan::query()
            ->where('member_id', $member->id)
            ->whereIn('status', ['active', 'completed', 'early_settled'])
            ->orderByDesc('disbursed_at')
            ->with(['loanTier', 'installments'])
            ->first();

        $loanDetails = null;

        if ($activeLoan !== null) {
            $allInstallments = $activeLoan->installments;
            $loanDetails = [
                'id' => $activeLoan->id,
                'status' => $activeLoan->status,
                'amount_approved' => (float) $activeLoan->amount_approved,
                'tier' => $activeLoan->loanTier?->label,
                'disbursed_at' => $activeLoan->disbursed_at?->toDateString(),
                'installments_total' => $allInstallments->count(),
                'installments_paid' => $allInstallments->where('status', 'paid')->count(),
                'installments_pending' => $allInstallments->whereIn('status', ['pending', 'overdue'])->count(),
                'next_due' => $allInstallments->where('status', 'pending')->sortBy('due_date')->first()?->due_date?->toDateString(),
            ];
        }

        $closing = $opening + $totalContributions - $totalRepayments;
        $currency = Setting::get('general', 'currency', 'USD');

        return [
            'opening_balance' => $opening,
            'total_contributions' => $totalContributions,
            'total_repayments' => $totalRepayments,
            'closing_balance' => $closing,
            'period' => $period,
            'period_label' => Carbon::create($year, $month, 1)->translatedFormat('F Y'),
            'currency' => $currency,
            'generated_at' => now()->toDateTimeString(),
            'cash_opening' => $openingCash,
            'fund_opening' => $openingFund,
            'cash_closing' => $cashAtEnd,
            'fund_closing' => $fundAtEnd,
            'contributions' => $periodContribs->map(fn (Contribution $c) => [
                'amount' => (float) $c->amount,
                'paid_at' => $c->paid_at?->toDateString(),
                'method' => $c->payment_method,
                'is_late' => (bool) $c->is_late,
            ])->all(),
            'period_installments' => $paidInstallments->map(fn (LoanInstallment $i) => [
                'installment_number' => $i->installment_number,
                'due_date' => $i->due_date?->toDateString(),
                'paid_at' => $i->paid_at?->toDateString(),
                'amount' => (float) $i->amount,
            ])->all(),
            'period_transactions' => $periodTransactions,
            'active_loan' => $loanDetails,
            'member_snapshot' => [
                'name' => $member->name,
                'member_number' => $member->member_number,
                'email' => $member->email,
                'phone' => $member->phone,
                'status' => $member->status,
                'joined_at' => $member->joined_at?->toDateString(),
                'monthly_contrib' => (float) $member->monthly_contribution_amount,
            ],
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
            ->where('transacted_at', '<=', $date->endOfDay())
            ->sum('amount');

        $debits = (float) Transaction::query()
            ->where('account_id', $accountId)
            ->where('type', 'debit')
            ->where('transacted_at', '<=', $date->endOfDay())
            ->sum('amount');

        return round($credits - $debits, 2);
    }
}
