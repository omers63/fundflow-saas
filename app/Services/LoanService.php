<?php

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Member;
use Illuminate\Support\Facades\DB;

class LoanService
{
    public function __construct(
        public AccountingService $accounting,
    ) {}

    /**
     * Check loan eligibility and return reasons if not eligible.
     *
     * @return array{eligible: bool, reasons: string[]}
     */
    public function checkEligibility(Member $member): array
    {
        $reasons = [];

        $membershipMonths = $member->joined_at->diffInMonths(now());
        if ($membershipMonths < 12) {
            $reasons[] = "Member must have at least 12 months of membership (currently {$membershipMonths} months).";
        }

        if ($member->status !== 'active') {
            $reasons[] = 'Member must be in active status.';
        }

        $recentMissed = $member->contributions()
            ->where('period', '>=', now()->subMonths(3)->startOfMonth())
            ->whereIn('status', ['pending', 'failed'])
            ->count();

        if ($recentMissed > 0) {
            $reasons[] = "Member has {$recentMissed} outstanding contribution(s) in the last 3 months.";
        }

        $hasActiveLoans = $member->loans()
            ->whereIn('status', ['approved', 'disbursed', 'repaying'])
            ->exists();

        if ($hasActiveLoans) {
            $reasons[] = 'Member already has an active loan (only one active loan allowed).';
        }

        return [
            'eligible' => empty($reasons),
            'reasons' => $reasons,
        ];
    }

    /**
     * Apply for a loan.
     */
    public function applyForLoan(Member $member, float $amount, float $interestRate, int $termMonths): Loan
    {
        $totalDue = $amount + ($amount * $interestRate / 100);
        $monthlyRepayment = round($totalDue / $termMonths, 2);

        return Loan::create([
            'member_id' => $member->id,
            'amount' => $amount,
            'interest_rate' => $interestRate,
            'term_months' => $termMonths,
            'monthly_repayment' => $monthlyRepayment,
            'total_repaid' => 0,
            'status' => 'pending',
            'applied_at' => now(),
        ]);
    }

    /**
     * Approve a pending loan.
     */
    public function approveLoan(Loan $loan): void
    {
        $loan->update([
            'status' => 'approved',
            'approved_at' => now(),
        ]);
    }

    /**
     * Disburse an approved loan per the fund flow rules:
     * 1. Debit Master Fund for the full disbursement amount
     * 2. Debit Member Fund by the same amount (can go negative)
     * 3. Credit Member Cash account
     */
    public function disburseLoan(Loan $loan): void
    {
        DB::transaction(function () use ($loan) {
            $masterFund = Account::masterFund();
            $memberFund = $loan->member->fundAccount;
            $memberCash = $loan->member->cashAccount;
            $amount = (float) $loan->amount;
            $description = "Loan disbursement #{$loan->id}";

            $this->accounting->debit($masterFund, $amount, $description, $loan);

            $this->accounting->debit($memberFund, $amount, $description, $loan);

            $this->accounting->credit($memberCash, $amount, $description, $loan);

            $loan->update([
                'status' => 'disbursed',
                'disbursed_at' => now(),
            ]);
        });
    }

    /**
     * Pay out the loan to the member (actual bank transfer):
     * 1. Debit Member Cash (money leaves the system)
     * 2. Debit Master Cash (mirror)
     *
     * This is matched by a future bank statement import showing the outgoing transfer.
     */
    public function payoutLoan(Loan $loan): void
    {
        DB::transaction(function () use ($loan) {
            $memberCash = $loan->member->cashAccount;
            $masterCash = Account::masterCash();
            $amount = (float) $loan->amount;
            $description = "Loan payout #{$loan->id}";

            $this->accounting->debit($memberCash, $amount, $description, $loan);

            $this->accounting->debit($masterCash, $amount, $description, $loan);

            $loan->update(['status' => 'repaying']);
        });
    }

    /**
     * Record a loan repayment (processed via contribution cycle):
     * 1. Debit Member Cash
     * 2. Credit Member Fund
     * 3. Credit Master Fund
     * 4. Update loan total_repaid
     * 5. Mark loan completed if fully repaid
     */
    public function recordRepayment(Loan $loan, float $amount): LoanRepayment
    {
        return DB::transaction(function () use ($loan, $amount) {
            $repayment = LoanRepayment::create([
                'loan_id' => $loan->id,
                'amount' => $amount,
                'paid_at' => now(),
            ]);

            $memberCash = $loan->member->cashAccount;
            $memberFund = $loan->member->fundAccount;
            $masterFund = Account::masterFund();
            $description = "Loan repayment #{$loan->id}";

            $this->accounting->debit($memberCash, $amount, $description, $repayment);

            $this->accounting->credit($memberFund, $amount, $description, $repayment);

            $this->accounting->mirror($masterFund, $amount, "Fund recovery: {$description}", $repayment);

            $loan->update([
                'total_repaid' => (float) $loan->total_repaid + $amount,
                'status' => 'repaying',
            ]);

            $loan->refresh();
            if ($loan->isFullyRepaid()) {
                $loan->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);
            }

            return $repayment;
        });
    }
}
