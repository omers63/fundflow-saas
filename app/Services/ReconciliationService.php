<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\Transaction;
use App\Services\Loans\LateFeeService;
use App\Services\Loans\LoanLedgerService;
use App\Support\BatchPostingGate;
use App\Support\ContributionCollectionStatus;
use App\Support\ContributionPolicySettings;
use App\Support\InstallmentCollectionStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReconciliationService
{
    public function __construct(
        protected MasterAccountInvariantService $masterInvariants,
        protected MemberInvariantService $memberInvariants,
        protected FundAuditLogService $audit,
        protected BatchPostingGate $batchGate,
        protected AccountingService $accounting,
        protected ReconciliationSuspenseService $suspense,
        protected ContributionCycleService $contributionCycles,
        protected BankClearingMatchService $bankClearing,
        protected LoanLedgerService $loanLedger,
        protected LateFeeService $lateFees,
        protected ContributionCollectionCycleService $contributionCollection,
    ) {}

    /**
     * @return array{halted: bool, raised: int, resolved: int, critical: int}
     */
    public function runNightlyBatch(): array
    {
        $raised = 0;
        $resolved = 0;
        $critical = 0;

        $this->audit->log('NIGHTLY_RECON_START', 'reconciliation');

        $escalatedDeferred = $this->suspense->escalateDeferredExceptions();

        try {
            $this->assertMasterBalancedOrRound();
            $this->batchGate->clear();
        } catch (\InvalidArgumentException $exception) {
            $this->raiseOnce('MASTER_IMBALANCE_UNRESOLVED', 'master_account', 'critical', null, [
                'message' => $exception->getMessage(),
            ]);
            $this->batchGate->halt($exception->getMessage());
            $critical++;

            return ['halted' => true, 'raised' => $critical, 'resolved' => 0, 'critical' => $critical];
        }

        $raised += $this->reconcileContributions();
        $raised += $this->reconcileLoansAndEmi();
        $raised += $this->reconcileFundTiers();
        $raised += $this->reconcileBankClearing();
        $raised += $this->reconcileLateFees();
        $raised += $this->reconcileMemberInvariants();

        $open = ReconciliationException::query()->open()->get();

        foreach ($open as $exception) {
            if ($this->attemptAutoResolve($exception)) {
                $resolved++;
            }
        }

        try {
            $this->assertMasterBalancedOrRound();
            $this->batchGate->clear();
        } catch (\InvalidArgumentException $exception) {
            $this->raiseOnce('MASTER_IMBALANCE_UNRESOLVED', 'master_account', 'critical', null, [
                'message' => $exception->getMessage(),
                'phase' => 'post_batch',
            ]);
            $this->batchGate->halt($exception->getMessage());
            $critical++;
        }

        $this->audit->log('NIGHTLY_RECON_COMPLETE', 'reconciliation', payload: [
            'raised' => $raised,
            'resolved' => $resolved,
            'critical' => $critical,
            'escalated_deferred' => $escalatedDeferred,
        ]);

        return [
            'halted' => $critical > 0,
            'raised' => $raised,
            'resolved' => $resolved,
            'critical' => $critical,
        ];
    }

    public function attemptAutoResolveForAdmin(ReconciliationException $exception): bool
    {
        return $this->attemptAutoResolve($exception);
    }

    public function onTransactionPosted(Transaction $transaction): void
    {
        $this->audit->log(
            'TRANSACTION_POSTED',
            'ledger',
            $transaction,
            $transaction->member,
            [
                'account_id' => $transaction->account_id,
                'type' => $transaction->type,
                'amount' => (float) $transaction->amount,
            ],
        );

        $imbalance = $this->accounting->validateBalancedJournalForReference($transaction);

        if ($imbalance !== null) {
            $this->raiseOnce('UNBALANCED_ENTRY', 'master_account', 'critical', null, [
                'transaction_id' => $transaction->id,
                'message' => $imbalance,
            ]);
            $this->attemptVoidUnbalancedOrIneligible($transaction, $imbalance);
        }

        $this->handleRealtimeLateFeeRules($transaction);
        $this->handleRealtimeMemberInvariant($transaction);
        $this->handleRealtimeMasterPoolInvariant($transaction);
    }

    protected function handleRealtimeMasterPoolInvariant(Transaction $transaction): void
    {
        if (AccountingService::masterPoolMirrorInProgress()) {
            return;
        }

        $transaction->loadMissing('account');

        if ($transaction->account === null) {
            return;
        }

        if (! in_array($transaction->account->type, ['cash', 'fund'], true)) {
            return;
        }

        $result = $this->masterInvariants->check();
        $tolerance = ContributionPolicySettings::reconTolerance();

        if ($result['cash_delta'] > $tolerance) {
            $this->raiseOnce('MASTER_CASH_POOL_DRIFT', 'master_account', 'high', $result['cash_delta'], [
                'transaction_id' => $transaction->id,
                'master_cash' => $result['master_cash'],
                'member_cash_sum' => $result['member_cash_sum'],
                'realtime' => true,
            ]);
        }

        if ($result['fund_delta'] > $tolerance) {
            $this->raiseOnce('MASTER_FUND_POOL_DRIFT', 'master_account', 'high', $result['fund_delta'], [
                'transaction_id' => $transaction->id,
                'master_fund' => $result['master_fund'],
                'member_fund_sum' => $result['member_fund_sum'],
                'realtime' => true,
            ]);
        }
    }

    protected function handleRealtimeMemberInvariant(Transaction $transaction): void
    {
        if (AccountingService::masterPoolMirrorInProgress()) {
            return;
        }

        $member = $transaction->member;

        if ($member === null) {
            return;
        }

        $transaction->loadMissing('account');

        if ($transaction->account === null || $transaction->account->is_master) {
            return;
        }

        if (! in_array($transaction->account->type, ['cash', 'fund'], true)) {
            return;
        }

        $tolerance = ContributionPolicySettings::reconTolerance();
        $result = $this->memberInvariants->check($member);

        if ($result['fund_drift'] > $tolerance) {
            $this->raiseOnce('MEMBER_FUND_DRIFT', 'master_account', 'medium', $result['fund_drift'], [
                'member_id' => $member->id,
                'transaction_id' => $transaction->id,
                'realtime' => true,
            ]);
        }

        if ($result['cash_drift'] > $tolerance) {
            $this->raiseOnce('MEMBER_CASH_DRIFT', 'master_account', 'medium', $result['cash_drift'], [
                'member_id' => $member->id,
                'transaction_id' => $transaction->id,
                'realtime' => true,
            ]);
        }
    }

    protected function attemptVoidUnbalancedOrIneligible(Transaction $transaction, string $reason): void
    {
        if ($this->accounting->isReversalEntry($transaction) || $this->accounting->hasExistingReversal($transaction)) {
            return;
        }

        try {
            $this->accounting->createReversalEntry($transaction, $reason);
        } catch (\Throwable) {
            // Leave exception open for manual reversal if auto-void fails.
        }
    }

    protected function handleRealtimeLateFeeRules(Transaction $transaction): void
    {
        $description = (string) $transaction->description;

        if (! str_contains(strtolower($description), 'late fee')) {
            return;
        }

        $member = $transaction->member;

        if ($member === null) {
            return;
        }

        if (
            str_contains($description, 'Contribution late fee')
            && $member->isExemptFromContributions()
        ) {
            $this->raiseOnce('RECON_AUTO_FEE_EXEMPTION_REVERSAL', 'late_fee', 'low', (float) $transaction->amount, [
                'transaction_id' => $transaction->id,
                'member_id' => $member->id,
                'reason' => 'loan_exempt',
            ]);
        }
    }

    protected function reconcileContributions(): int
    {
        $count = 0;

        Contribution::query()
            ->where('status', 'posted')
            ->where('collection_status', ContributionCollectionStatus::COLLECTED)
            ->with('member')
            ->each(function (Contribution $contribution) use (&$count): void {
                if (
                    $contribution->member?->isExemptFromContributions(
                        (int) $contribution->period?->month,
                        (int) $contribution->period?->year,
                    )
                ) {
                    $this->raiseOnce('CONTRIBUTION_EXEMPT_COLLECTED', 'contribution', 'medium', null, [
                        'contribution_id' => $contribution->id,
                        'member_id' => $contribution->member_id,
                    ]);
                    $count++;
                }
            });

        Contribution::query()
            ->where('status', 'pending')
            ->where('collection_status', ContributionCollectionStatus::COLLECTED)
            ->each(function (Contribution $contribution) use (&$count): void {
                $this->raiseOnce('COLLECTED_WITHOUT_POST', 'contribution', 'high', null, [
                    'contribution_id' => $contribution->id,
                ]);
                $count++;
            });

        Contribution::query()
            ->where('status', 'pending')
            ->whereIn('collection_status', [
                ContributionCollectionStatus::PENDING,
                ContributionCollectionStatus::PARTIALLY_PENDING,
            ])
            ->with('member')
            ->each(function (Contribution $contribution) use (&$count): void {
                if ($contribution->period === null) {
                    return;
                }

                $month = (int) $contribution->period->month;
                $year = (int) $contribution->period->year;

                if ($contribution->member?->isExemptFromContributions($month, $year)) {
                    return;
                }

                if (now()->greaterThan($this->contributionCycles->cycleDueEndAt($month, $year))) {
                    $this->raiseOnce('PENDING_PAST_WINDOW_CLOSE', 'contribution', 'medium', null, [
                        'contribution_id' => $contribution->id,
                        'member_id' => $contribution->member_id,
                    ]);
                    $count++;
                }
            });

        $duplicateGroups = Contribution::query()
            ->where('status', 'posted')
            ->selectRaw('member_id, period, COUNT(*) as duplicate_count')
            ->groupBy('member_id', 'period')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateGroups as $row) {
            $this->raiseOnce('DUPLICATE_CONTRIBUTION_DEBIT', 'contribution', 'high', null, [
                'member_id' => (int) $row->member_id,
                'period' => (string) $row->period,
                'count' => (int) $row->duplicate_count,
            ]);
            $count++;
        }

        Contribution::query()
            ->where('status', 'posted')
            ->where('payment_method', Contribution::PAYMENT_METHOD_CASH_ACCOUNT)
            ->with('member.cashAccount')
            ->each(function (Contribution $contribution) use (&$count): void {
                $cashAccountId = $contribution->member?->cashAccount?->id;

                if ($cashAccountId === null) {
                    return;
                }

                $hasCashDebit = Transaction::query()
                    ->where('account_id', $cashAccountId)
                    ->where('member_id', $contribution->member_id)
                    ->where('type', 'debit')
                    ->where('reference_type', $contribution->getMorphClass())
                    ->where('reference_id', $contribution->id)
                    ->exists();

                if (! $hasCashDebit) {
                    $this->raiseOnce('ORPHAN_MASTER_FUND_CREDIT', 'contribution', 'high', (float) $contribution->amount, [
                        'contribution_id' => $contribution->id,
                        'member_id' => $contribution->member_id,
                    ]);
                    $count++;
                }
            });

        $masterFundId = Account::masterFund()?->id;

        if ($masterFundId !== null) {
            Contribution::query()
                ->where('status', 'posted')
                ->each(function (Contribution $contribution) use (&$count, $masterFundId): void {
                    $hasMasterCredit = Transaction::query()
                        ->where('account_id', $masterFundId)
                        ->where('type', 'credit')
                        ->where('reference_type', $contribution->getMorphClass())
                        ->where('reference_id', $contribution->id)
                        ->exists();

                    if (! $hasMasterCredit) {
                        $this->raiseOnce('CONTRIBUTION_MISSING_MASTER_CREDIT', 'contribution', 'high', (float) $contribution->amount, [
                            'contribution_id' => $contribution->id,
                            'member_id' => $contribution->member_id,
                        ]);
                        $count++;
                    }
                });
        }

        Contribution::query()
            ->where('status', 'posted')
            ->with('member.fundAccount')
            ->each(function (Contribution $contribution) use (&$count): void {
                $fundAccountId = $contribution->member?->fundAccount?->id;

                if ($fundAccountId === null) {
                    return;
                }

                $hasMemberFundCredit = Transaction::query()
                    ->where('account_id', $fundAccountId)
                    ->where('member_id', $contribution->member_id)
                    ->where('type', 'credit')
                    ->where('reference_type', $contribution->getMorphClass())
                    ->where('reference_id', $contribution->id)
                    ->exists();

                if (! $hasMemberFundCredit) {
                    $this->raiseOnce('CONTRIBUTION_MEMBER_FUND_MISSING', 'contribution', 'high', (float) $contribution->amount, [
                        'contribution_id' => $contribution->id,
                        'member_id' => $contribution->member_id,
                    ]);
                    $count++;
                }
            });

        Contribution::query()
            ->where('status', 'posted')
            ->each(function (Contribution $contribution) use (&$count): void {
                $due = (float) ($contribution->amount_due ?? $contribution->amount);
                $collected = (float) ($contribution->amount_collected ?? $contribution->amount);
                $tolerance = ContributionPolicySettings::reconTolerance();

                if (abs($collected - $due) > $tolerance) {
                    $this->raiseOnce('CONTRIBUTION_AMOUNT_MISMATCH', 'contribution', 'medium', $collected - $due, [
                        'contribution_id' => $contribution->id,
                        'member_id' => $contribution->member_id,
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    protected function reconcileLoansAndEmi(): int
    {
        $count = 0;

        Loan::query()
            ->whereIn('status', ['active', 'transferred'])
            ->each(function (Loan $loan) use (&$count): void {
                $approved = (float) ($loan->amount_approved ?? 0);
                $disbursed = (float) ($loan->amount_disbursed ?? 0);

                if ($approved > 0 && $disbursed < $approved - 0.01) {
                    $unpaid = $loan->installments()->whereIn('status', ['paid'])->exists();

                    if ($unpaid) {
                        $this->raiseOnce('ACTIVE_BEFORE_FULL_DISBURSE', 'loan', 'high', $approved - $disbursed, [
                            'loan_id' => $loan->id,
                        ]);
                        $count++;
                    }
                }

                $pending = $loan->installments()->whereIn('status', ['pending', 'overdue'])->count();
                $paid = $loan->installments()->where('status', 'paid')->count();

                if ($pending === 0 && $paid > 0 && in_array($loan->status, ['active', 'transferred'], true)) {
                    $loan->syncPaidOffStatusFromInstallments();
                }
            });

        LoanInstallment::query()
            ->whereIn('status', ['pending', 'overdue'])
            ->whereHas('loan', function ($q): void {
                $q->whereIn('status', ['active', 'transferred'])
                    ->whereNotNull('exempted_month')
                    ->whereNotNull('exempted_year');
            })
            ->with('loan')
            ->each(function (LoanInstallment $installment) use (&$count): void {
                $loan = $installment->loan;
                $due = $installment->due_date;
                $exemptM = (int) $loan->exempted_month;
                $exemptY = (int) $loan->exempted_year;

                if ((int) $due->month === $exemptM && (int) $due->year === $exemptY) {
                    $this->raiseOnce('GRACE_CYCLE_EMI_DEBIT', 'emi', 'medium', null, [
                        'installment_id' => $installment->id,
                        'loan_id' => $loan->id,
                    ]);
                    $count++;
                }
            });

        LoanInstallment::query()
            ->where('status', 'overdue')
            ->whereNull('overdue_since')
            ->whereHas('loan', fn ($q) => $q->whereIn('status', ['active', 'transferred']))
            ->each(function (LoanInstallment $installment) use (&$count): void {
                $this->raiseOnce('EMI_OVERDUE_WITHOUT_CLOCK', 'emi', 'medium', null, [
                    'installment_id' => $installment->id,
                    'loan_id' => $installment->loan_id,
                ]);
                $count++;
            });

        Loan::query()
            ->where('amount_disbursed', '>', 0)
            ->with('member.cashAccount')
            ->each(function (Loan $loan) use (&$count): void {
                $cashAccountId = $loan->member?->cashAccount?->id;

                if ($cashAccountId === null) {
                    return;
                }

                $hasPayout = Transaction::query()
                    ->where('account_id', $cashAccountId)
                    ->where('member_id', $loan->member_id)
                    ->where('type', 'credit')
                    ->where('reference_type', $loan->getMorphClass())
                    ->where('reference_id', $loan->id)
                    ->where('description', 'like', '%(cash payout)%')
                    ->exists();

                $masterFundDebited = Transaction::query()
                    ->whereHas('account', fn ($q) => $q->where('is_master', true)->where('type', 'fund'))
                    ->where('type', 'debit')
                    ->where('reference_type', $loan->getMorphClass())
                    ->where('reference_id', $loan->id)
                    ->exists();

                if ($masterFundDebited && ! $hasPayout) {
                    $this->raiseOnce('DISBURSEMENT_MEMBER_CASH_MISSING', 'loan', 'high', (float) $loan->amount_disbursed, [
                        'loan_id' => $loan->id,
                        'member_id' => $loan->member_id,
                    ]);
                    $count++;
                }
            });

        Loan::query()
            ->whereIn('status', ['active', 'transferred', 'approved'])
            ->whereNotNull('fund_tier_id')
            ->with('installments')
            ->each(function (Loan $loan) use (&$count): void {
                $threshold = $loan->fullRepaymentThreshold();
                $collected = $loan->totalPrincipalCollected();
                $tolerance = ContributionPolicySettings::reconTolerance();
                $excess = $collected - $threshold;

                if ($excess <= $tolerance) {
                    return;
                }

                $emiAmount = $loan->representativeEmiAmount();
                $severity = $excess > $emiAmount + $tolerance ? 'high' : 'medium';

                $this->raiseOnce('EMI_OVER_COLLECTION', 'emi', $severity, $excess, [
                    'loan_id' => $loan->id,
                    'member_id' => $loan->member_id,
                    'threshold' => $threshold,
                    'collected' => $collected,
                    'emi_amount' => $emiAmount,
                ]);
                $count++;
            });

        LoanInstallment::query()
            ->where('status', 'paid')
            ->with(['loan.member', 'loan'])
            ->each(function (LoanInstallment $installment) use (&$count): void {
                $loan = $installment->loan;
                $loanAccount = Account::query()
                    ->where('type', Account::TYPE_LOAN)
                    ->where('loan_id', $loan->id)
                    ->first();

                if ($loanAccount === null) {
                    return;
                }

                $hasRepaymentCredit = Transaction::query()
                    ->where('account_id', $loanAccount->id)
                    ->where('type', 'credit')
                    ->where('reference_type', $installment->getMorphClass())
                    ->where('reference_id', $installment->id)
                    ->exists();

                if (! $hasRepaymentCredit) {
                    $this->raiseOnce('EMI_COLLECTED_LEDGER_MISSING', 'emi', 'medium', (float) $installment->amount, [
                        'installment_id' => $installment->id,
                        'loan_id' => $loan->id,
                    ]);
                    $count++;
                }
            });

        LoanInstallment::query()
            ->where('status', 'overdue')
            ->with(['loan.member.cashAccount'])
            ->each(function (LoanInstallment $installment) use (&$count): void {
                $member = $installment->loan?->member;
                $cashBalance = (float) ($member?->cashAccount?->balance ?? 0);
                $due = (float) $installment->amount + (float) ($installment->late_fee_amount ?? 0);
                $tolerance = ContributionPolicySettings::reconTolerance();

                if ($cashBalance + $tolerance >= $due && $due > 0.00001) {
                    $this->raiseOnce('EMI_MISSED_SUFFICIENT_CASH', 'emi', 'high', $due - $cashBalance, [
                        'installment_id' => $installment->id,
                        'loan_id' => $installment->loan_id,
                        'member_id' => $member?->id,
                        'cash_balance' => $cashBalance,
                    ]);
                    $count++;
                }
            });

        LoanInstallment::query()
            ->where('paid_by_guarantor', true)
            ->with(['loan.member', 'loan.guarantor'])
            ->each(function (LoanInstallment $installment) use (&$count): void {
                $loan = $installment->loan;
                $borrowerId = $loan->member_id;
                $guarantorId = $loan->guarantor_member_id;

                if ($guarantorId === null) {
                    return;
                }

                $borrowerDebited = Transaction::query()
                    ->where('member_id', $borrowerId)
                    ->where('type', 'debit')
                    ->where('reference_type', $installment->getMorphClass())
                    ->where('reference_id', $installment->id)
                    ->whereHas('account', fn ($q) => $q->where('type', 'cash')->where('is_master', false))
                    ->exists();

                $guarantorDebited = Transaction::query()
                    ->where('member_id', $guarantorId)
                    ->where('type', 'debit')
                    ->where('reference_type', $installment->getMorphClass())
                    ->where('reference_id', $installment->id)
                    ->whereHas('account', fn ($q) => $q->where('type', 'fund')->where('is_master', false))
                    ->exists();

                if ($borrowerDebited && $guarantorDebited) {
                    $this->raiseOnce('GUARANTOR_BORROWER_DUPLICATE_DEBIT', 'emi', 'high', (float) $installment->amount, [
                        'installment_id' => $installment->id,
                        'loan_id' => $loan->id,
                        'borrower_id' => $borrowerId,
                        'guarantor_id' => $guarantorId,
                    ]);
                    $count++;
                }
            });

        Loan::query()
            ->whereIn('status', ['active', 'approved'])
            ->whereColumn('amount_disbursed', '<', 'amount_approved')
            ->whereHas('installments')
            ->each(function (Loan $loan) use (&$count): void {
                $paidInstallments = $loan->installments()->where('status', 'paid')->exists();

                if ($paidInstallments) {
                    $this->raiseOnce('SCHEDULE_BEFORE_FULL_DISBURSE', 'loan', 'high', $loan->remainingToDisburse(), [
                        'loan_id' => $loan->id,
                        'member_id' => $loan->member_id,
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    protected function reconcileFundTiers(): int
    {
        $count = 0;
        $tolerance = ContributionPolicySettings::reconTolerance();

        FundTier::query()
            ->where('is_active', true)
            ->each(function (FundTier $tier) use (&$count, $tolerance): void {
                $allocated = $tier->allocated_amount;
                $exposure = $tier->active_exposure;
                $over = $exposure - $allocated;

                if ($over > $tolerance) {
                    $this->raiseOnce('FUND_TIER_OVER_COMMITTED', 'loan', 'high', $over, [
                        'fund_tier_id' => $tier->id,
                        'allocated' => $allocated,
                        'exposure' => $exposure,
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    protected function reconcileBankClearing(): int
    {
        $count = 0;
        $staleDays = ContributionPolicySettings::stalePendingDays();
        $tolerance = ContributionPolicySettings::reconTolerance();

        $scan = $this->bankClearing->scanMatchExceptions();

        foreach ($scan['ambiguous'] as $row) {
            $this->raiseOnce('RECON_AMBIGUOUS_MATCH', 'bank_clearing', 'high', null, [
                'imported_bank_transaction_id' => $row['imported_bank_transaction_id'],
                'candidate_ids' => $row['candidate_ids'],
            ]);
            $count++;
        }

        foreach ($scan['unmatched_imported'] as $importedId) {
            $imported = BankTransaction::query()->find($importedId);
            $this->raiseOnce('RECON_UNMATCHED_BANK_LINE', 'bank_clearing', 'high', $imported ? (float) $imported->amount : null, [
                'bank_transaction_id' => $importedId,
            ]);
            $count++;
        }

        BankTransaction::query()
            ->uncleared()
            ->whereNotNull('fund_posting_id')
            ->where('created_at', '<', now()->subDays($staleDays))
            ->whereDoesntHave('bankStatement', function ($query): void {
                $query->whereIn('filename', $this->bankClearing->membershipImportPlaceholderStatementFilenames());
            })
            ->each(function (BankTransaction $txn) use (&$count): void {
                $this->raiseOnce('UNMATCHED_CASH_ENTRY', 'bank_clearing', 'medium', (float) $txn->amount, [
                    'bank_transaction_id' => $txn->id,
                    'fund_posting_id' => $txn->fund_posting_id,
                ]);
                $count++;
            });

        BankTransaction::query()
            ->uncleared()
            ->where('created_at', '<', now()->subDays($staleDays))
            ->whereDoesntHave('bankStatement', function ($query): void {
                $query->whereIn('filename', $this->bankClearing->membershipImportPlaceholderStatementFilenames());
            })
            ->each(function (BankTransaction $txn) use (&$count): void {
                $this->raiseOnce('STALE_PENDING', 'bank_clearing', 'medium', (float) $txn->amount, [
                    'bank_transaction_id' => $txn->id,
                ]);
                $count++;
            });

        FundPosting::query()
            ->where('status', 'accepted')
            ->whereNull('bank_transaction_id')
            ->where('reviewed_at', '<', now()->subDays(ContributionPolicySettings::cashDepositUnbankedDays()))
            ->each(function (FundPosting $posting) use (&$count): void {
                $this->raiseOnce('CASH_DEPOSIT_UNBANKED', 'bank_clearing', 'medium', (float) $posting->amount, [
                    'fund_posting_id' => $posting->id,
                    'member_id' => $posting->member_id,
                ]);
                $count++;
            });

        FundPosting::query()
            ->whereNotNull('bank_transaction_id')
            ->with('bankTransaction')
            ->each(function (FundPosting $posting) use (&$count, $tolerance): void {
                $bank = $posting->bankTransaction;

                if ($bank === null) {
                    return;
                }

                if (abs((float) $posting->amount - (float) $bank->amount) > $tolerance) {
                    $this->raiseOnce('AMOUNT_MISMATCH', 'bank_clearing', 'high', (float) $posting->amount - (float) $bank->amount, [
                        'fund_posting_id' => $posting->id,
                        'bank_transaction_id' => $bank->id,
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    protected function reconcileLateFees(): int
    {
        $count = 0;
        $tolerance = ContributionPolicySettings::reconTolerance();

        $masterFees = (float) (Account::query()->where('is_master', true)->where('type', 'fees')->value('balance') ?? 0);
        $postedFees = (float) Transaction::query()
            ->whereHas('account', fn ($q) => $q->where('is_master', true)->where('type', 'fees'))
            ->where('type', 'credit')
            ->sum('amount');

        if (abs($masterFees - $postedFees) > $tolerance) {
            $this->raiseOnce('FEE_INCOME_DRIFT', 'late_fee', 'high', $masterFees - $postedFees, []);
            $count++;
        }

        Transaction::query()
            ->where(function ($query): void {
                $query->where('description', 'like', '%late fee%')
                    ->orWhere('description', 'like', '%Late fee%');
            })
            ->with('account')
            ->each(function (Transaction $transaction) use (&$count): void {
                $account = $transaction->account;

                if ($account === null) {
                    return;
                }

                if ($this->lateFeePostedToWrongAccount($account, (string) $transaction->type)) {
                    $this->raiseOnce('FEE_POSTED_WRONG_ACCOUNT', 'late_fee', 'high', (float) $transaction->amount, [
                        'transaction_id' => $transaction->id,
                        'account_id' => $account->id,
                        'member_id' => $transaction->member_id,
                    ]);
                    $count++;
                }
            });

        Contribution::query()
            ->whereNotNull('late_fee_amount')
            ->where('late_fee_amount', '>', 0)
            ->whereNotNull('overdue_since')
            ->each(function (Contribution $contribution) use (&$count, $tolerance): void {
                $days = $this->lateFees->daysPastDue(
                    Carbon::parse($contribution->overdue_since),
                    now(),
                );
                $expectedTier = ContributionCollectionStatus::tierForDays($days);

                if ($expectedTier === null) {
                    return;
                }

                $expectedFee = $this->lateFees->contributionLateFeeForTier($expectedTier);
                $actualFee = (float) $contribution->late_fee_amount;
                $actualTier = (int) ($contribution->late_fee_tier ?? 0);

                if ($actualTier !== $expectedTier || abs($actualFee - $expectedFee) > $tolerance) {
                    $this->raiseOnce('FEE_WRONG_TIER', 'late_fee', 'medium', $actualFee - $expectedFee, [
                        'contribution_id' => $contribution->id,
                        'expected_tier' => $expectedTier,
                        'actual_tier' => $actualTier,
                    ]);
                    $count++;
                }
            });

        if (ContributionPolicySettings::lateFeeModel() === 'replacement') {
            Contribution::query()
                ->where('late_fee_amount', '>', 0)
                ->each(function (Contribution $contribution) use (&$count): void {
                    $debitCount = Transaction::query()
                        ->where('reference_type', $contribution->getMorphClass())
                        ->where('reference_id', $contribution->id)
                        ->where('type', 'debit')
                        ->where(function ($query): void {
                            $query->where('description', 'like', '%late fee%')
                                ->orWhere('description', 'like', '%Late fee%');
                        })
                        ->count();

                    if ($debitCount > 1) {
                        $this->raiseOnce('REPLACEMENT_PRIOR_TIER_NOT_REVERSED', 'late_fee', 'medium', null, [
                            'contribution_id' => $contribution->id,
                            'debit_count' => $debitCount,
                        ]);
                        $count++;
                    }
                });
        }

        return $count;
    }

    /**
     * Valid late-fee journal legs per pool mirroring rules:
     * - Application: DR member cash + DR master cash (mirror), CR master fees.
     * - Reversal: CR member cash + CR master cash (mirror), DR master fees.
     */
    protected function lateFeePostedToWrongAccount(Account $account, string $transactionType): bool
    {
        $allowed = match ($transactionType) {
            'credit' => ($account->is_master && $account->type === 'fees')
            || $account->type === 'cash',
            'debit' => $account->type === 'cash'
            || ($account->is_master && $account->type === 'fees'),
            default => false,
        };

        return ! $allowed;
    }

    protected function reconcileMemberInvariants(): int
    {
        $count = 0;
        $tolerance = ContributionPolicySettings::reconTolerance();

        Member::query()
            ->where('status', 'active')
            ->each(function (Member $member) use (&$count, $tolerance): void {
                $result = $this->memberInvariants->check($member);

                if ($result['fund_drift'] > $tolerance) {
                    $this->raiseOnce('MEMBER_FUND_DRIFT', 'master_account', 'medium', $result['fund_drift'], [
                        'member_id' => $member->id,
                    ]);
                    $count++;
                }

                if ($result['cash_drift'] > $tolerance) {
                    $this->raiseOnce('MEMBER_CASH_DRIFT', 'master_account', 'medium', $result['cash_drift'], [
                        'member_id' => $member->id,
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    protected function assertMasterBalancedOrRound(): void
    {
        $result = $this->masterInvariants->check();

        if ($result['balanced']) {
            return;
        }

        $tolerance = ContributionPolicySettings::reconTolerance();

        if ($result['fund_delta'] <= $tolerance) {
            $this->suspense->postRoundingAdjustment($result['master_fund'] - $result['expected_master_fund'], 'fund');
        } elseif ($result['cash_delta'] <= $tolerance) {
            $this->suspense->postRoundingAdjustment($result['master_cash'] - $result['member_cash_sum'], 'cash');
        }

        $final = $this->masterInvariants->check();

        if (! $final['balanced']) {
            if ($final['cash_delta'] > $tolerance) {
                $this->raiseOnce('MASTER_CASH_POOL_DRIFT', 'master_account', 'critical', $final['cash_delta'], [
                    'master_cash' => $final['master_cash'],
                    'member_cash_sum' => $final['member_cash_sum'],
                ]);
            }

            if ($final['fund_delta'] > $tolerance) {
                $this->raiseOnce('MASTER_FUND_POOL_DRIFT', 'master_account', 'critical', $final['fund_delta'], [
                    'master_fund' => $final['master_fund'],
                    'member_fund_sum' => $final['member_fund_sum'],
                ]);
            }
        }

        $this->masterInvariants->assert();
    }

    protected function attemptAutoResolve(ReconciliationException $exception): bool
    {
        if ($this->suspense->isDeferred($exception)) {
            return false;
        }

        $tolerance = ContributionPolicySettings::reconTolerance();

        if (
            $exception->amount_delta !== null
            && abs((float) $exception->amount_delta) <= $tolerance
            && $exception->severity !== 'critical'
        ) {
            $this->suspense->postRoundingAdjustment((float) $exception->amount_delta);
            $this->resolveException($exception, __('Rounding adjustment posted'), true);

            return true;
        }

        if ($exception->exception_code === 'STALE_PENDING') {
            $this->suspense->deferTimingException($exception);

            return false;
        }

        if ($exception->exception_code === 'CONTRIBUTION_EXEMPT_COLLECTED') {
            return $this->autoReverseExemptContribution($exception);
        }

        if ($exception->exception_code === 'PENDING_PAST_WINDOW_CLOSE') {
            $contributionId = (int) ($exception->affected_entities['contribution_id'] ?? 0);
            $contribution = Contribution::query()->find($contributionId);

            if ($contribution && $contribution->status === 'pending') {
                app(ContributionCollectionCycleService::class)->attemptCollection($contribution);
                $contribution->refresh();

                if ($contribution->collection_status === ContributionCollectionStatus::COLLECTED) {
                    $this->resolveException($exception, __('Re-ran collection after window close'));

                    return true;
                }
            }
        }

        if ($exception->exception_code === 'COLLECTED_WITHOUT_POST') {
            $contributionId = (int) ($exception->affected_entities['contribution_id'] ?? 0);
            $contribution = Contribution::query()->find($contributionId);

            if ($contribution && $contribution->status === 'pending') {
                $contribution->update([
                    'collection_status' => ContributionCollectionStatus::PENDING,
                    'amount_collected' => 0,
                ]);
                $this->resolveException($exception, __('Reset collection status to pending'));

                return true;
            }
        }

        if ($exception->exception_code === 'GRACE_CYCLE_EMI_DEBIT') {
            return $this->autoClearGraceCycleEmi($exception);
        }

        if ($exception->exception_code === 'EMI_OVERDUE_WITHOUT_CLOCK') {
            return $this->autoSetEmiOverdueClock($exception);
        }

        if ($exception->exception_code === 'RECON_AUTO_FEE_EXEMPTION_REVERSAL') {
            return $this->autoReverseFeeExemptionTransaction($exception);
        }

        if ($exception->exception_code === 'CONTRIBUTION_AMOUNT_MISMATCH') {
            $delta = abs((float) ($exception->amount_delta ?? 0));

            if ($delta <= $tolerance) {
                $this->suspense->postRoundingAdjustment((float) $exception->amount_delta);
                $this->resolveException($exception, __('Contribution amount within tolerance — rounding posted'), true);

                return true;
            }
        }

        if ($exception->exception_code === 'CONTRIBUTION_MISSING_MASTER_CREDIT') {
            return $this->autoPostMissingContributionMasterCredit($exception);
        }

        if ($exception->exception_code === 'EMI_COLLECTED_LEDGER_MISSING') {
            return $this->autoPostMissingEmiRepaymentLedger($exception);
        }

        if ($exception->exception_code === 'EMI_OVER_COLLECTION') {
            return $this->autoRefundEmiOverCollection($exception);
        }

        if ($exception->exception_code === 'CONTRIBUTION_MEMBER_FUND_MISSING') {
            return $this->autoPostMissingContributionMemberFund($exception);
        }

        if ($exception->exception_code === 'FEE_WRONG_TIER') {
            return $this->autoCorrectFeeTier($exception);
        }

        if ($exception->exception_code === 'REPLACEMENT_PRIOR_TIER_NOT_REVERSED') {
            return $this->autoCorrectFeeTier($exception);
        }

        return false;
    }

    protected function autoPostMissingContributionMemberFund(ReconciliationException $exception): bool
    {
        $contributionId = (int) ($exception->affected_entities['contribution_id'] ?? 0);
        $contribution = Contribution::query()->with('member.fundAccount')->find($contributionId);
        $memberFund = $contribution?->member?->fundAccount;

        if ($contribution === null || $memberFund === null || $contribution->status !== 'posted') {
            return false;
        }

        $amount = (float) ($contribution->amount_collected ?? $contribution->amount);

        if ($amount <= 0.00001) {
            return false;
        }

        try {
            $periodLabel = $contribution->period?->format('M Y') ?? '';
            $description = __('Contribution member fund correction — :period', ['period' => $periodLabel]);
            $masterFund = Account::masterFund();

            if ($masterFund === null) {
                return false;
            }

            $masterAlreadyCredited = Transaction::query()
                ->where('account_id', $masterFund->id)
                ->where('reference_type', Contribution::class)
                ->where('reference_id', $contribution->id)
                ->where('type', 'credit')
                ->where('amount', '>=', $amount - ContributionPolicySettings::reconTolerance())
                ->exists();

            if ($masterAlreadyCredited) {
                $this->accounting->credit($memberFund, $amount, $description, $contribution);
            } else {
                $this->accounting->creditMemberFundWithMasterMirror(
                    $memberFund,
                    $amount,
                    $description,
                    __('(recon correction mirror)'),
                    $contribution,
                );
            }

            $this->resolveException($exception, __('Posted missing member fund credit'));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function autoCorrectFeeTier(ReconciliationException $exception): bool
    {
        $contributionId = (int) ($exception->affected_entities['contribution_id'] ?? 0);
        $contribution = Contribution::query()->find($contributionId);

        if ($contribution === null) {
            return false;
        }

        try {
            if ($this->contributionCollection->applyLateFeeTierForContribution($contribution)) {
                $this->resolveException($exception, __('Re-applied correct late fee tier'), true);

                return true;
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    protected function autoPostMissingContributionMasterCredit(ReconciliationException $exception): bool
    {
        $contributionId = (int) ($exception->affected_entities['contribution_id'] ?? 0);
        $contribution = Contribution::query()->with('member')->find($contributionId);
        $masterFund = Account::masterFund();

        if ($contribution === null || $masterFund === null || $contribution->status !== 'posted') {
            return false;
        }

        $amount = (float) ($contribution->amount_collected ?? $contribution->amount);

        if ($amount <= 0.00001) {
            return false;
        }

        try {
            $periodLabel = $contribution->period?->format('M Y') ?? '';
            $description = __('Contribution master credit correction — :period', ['period' => $periodLabel]);
            $memberFund = $contribution->member?->fundAccount;

            if ($memberFund === null) {
                return false;
            }

            $memberAlreadyCredited = Transaction::query()
                ->where('account_id', $memberFund->id)
                ->where('reference_type', Contribution::class)
                ->where('reference_id', $contribution->id)
                ->where('type', 'credit')
                ->where('amount', '>=', $amount - ContributionPolicySettings::reconTolerance())
                ->exists();

            if ($memberAlreadyCredited) {
                $this->accounting->credit($masterFund, $amount, $description, $contribution);
            } else {
                $this->accounting->creditMemberFundWithMasterMirror(
                    $memberFund,
                    $amount,
                    $description,
                    __('(recon correction mirror)'),
                    $contribution,
                );
            }

            $this->resolveException($exception, __('Posted missing master fund credit'));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function autoPostMissingEmiRepaymentLedger(ReconciliationException $exception): bool
    {
        $installmentId = (int) ($exception->affected_entities['installment_id'] ?? 0);
        $installment = LoanInstallment::query()->with('loan')->find($installmentId);

        if ($installment === null || $installment->status !== 'paid') {
            return false;
        }

        try {
            $this->loanLedger->postLoanRepayment($installment);
            $this->resolveException($exception, __('Posted missing loan repayment ledger entries'));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function autoRefundEmiOverCollection(ReconciliationException $exception): bool
    {
        $loanId = (int) ($exception->affected_entities['loan_id'] ?? 0);
        $loan = Loan::query()->with('member')->find($loanId);

        if ($loan === null) {
            return false;
        }

        $excess = (float) ($exception->amount_delta ?? 0);
        $emiAmount = (float) ($exception->affected_entities['emi_amount'] ?? $loan->representativeEmiAmount());
        $tolerance = ContributionPolicySettings::reconTolerance();

        if ($excess <= $tolerance || $excess > $emiAmount + $tolerance) {
            return false;
        }

        try {
            app(ReconciliationCorrectionService::class)->postEmiOverpaymentRefund(
                $exception,
                $loan,
                $excess,
                __('Auto-refund EMI over-collection within one installment'),
            );
            $exception->update([
                'status' => ReconciliationException::STATUS_RESOLVED,
                'resolved_at' => now(),
                'auto_resolve_attempted' => true,
                'auto_resolve_reason' => __('EMI over-collection refunded'),
            ]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function autoClearGraceCycleEmi(ReconciliationException $exception): bool
    {
        $installmentId = (int) ($exception->affected_entities['installment_id'] ?? 0);
        $installment = LoanInstallment::query()->with('loan')->find($installmentId);

        if ($installment === null) {
            return false;
        }

        $installment->update([
            'status' => 'pending',
            'collection_status' => InstallmentCollectionStatus::PENDING,
            'late_fee_amount' => null,
            'late_fee_tier' => null,
            'is_late' => false,
        ]);

        $this->resolveException($exception, __('Cleared grace-period EMI flags'));

        return true;
    }

    protected function autoSetEmiOverdueClock(ReconciliationException $exception): bool
    {
        $installmentId = (int) ($exception->affected_entities['installment_id'] ?? 0);
        $installment = LoanInstallment::query()->find($installmentId);

        if ($installment === null || $installment->due_date === null) {
            return false;
        }

        $month = (int) $installment->due_date->month;
        $year = (int) $installment->due_date->year;
        $closedAt = $this->contributionCycles->cycleDueEndAt($month, $year);

        $installment->update(['overdue_since' => $closedAt]);
        $this->resolveException($exception, __('Set EMI overdue_since from cycle window close'));

        return true;
    }

    protected function autoReverseFeeExemptionTransaction(ReconciliationException $exception): bool
    {
        $transactionId = (int) ($exception->affected_entities['transaction_id'] ?? 0);
        $transaction = Transaction::query()->with('account')->find($transactionId);

        if ($transaction === null || $transaction->type !== 'debit') {
            return false;
        }

        try {
            $amount = (float) $transaction->amount;
            $this->accounting->credit(
                $transaction->account,
                $amount,
                __('RECON_AUTO_FEE_EXEMPTION_REVERSAL').': '.$transaction->description,
                $transaction,
            );
            $fees = Account::masterFees();
            if ($fees) {
                $this->accounting->debit($fees, $amount, __('RECON_AUTO_FEE_EXEMPTION_REVERSAL'), $transaction);
            }

            $this->resolveException($exception, __('Reversed ineligible late fee posting'), true);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function autoReverseExemptContribution(ReconciliationException $exception): bool
    {
        $contributionId = (int) ($exception->affected_entities['contribution_id'] ?? 0);
        $contribution = Contribution::query()->with('member')->find($contributionId);

        if ($contribution === null || $contribution->status !== 'posted') {
            return false;
        }

        try {
            DB::transaction(function () use ($contribution): void {
                $principal = (float) ($contribution->amount_collected ?? $contribution->amount);
                $late = (float) ($contribution->late_fee_amount ?? 0);

                if ($late > 0.00001) {
                    $this->accounting->reverseContributionLateFee($contribution, $late);
                }

                $this->accounting->reverseContributionPrincipal($contribution, $principal);

                $contribution->update([
                    'status' => 'failed',
                    'collection_status' => ContributionCollectionStatus::PENDING,
                    'amount_collected' => 0,
                    'posted_at' => null,
                    'late_fee_amount' => null,
                ]);
            });

            $this->resolveException($exception, __('Reversed exempt contribution collection'));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function resolveException(
        ReconciliationException $exception,
        string $reason,
        bool $auto = false,
    ): void {
        $exception->update([
            'status' => ReconciliationException::STATUS_RESOLVED,
            'auto_resolve_attempted' => $auto,
            'auto_resolve_reason' => $reason,
            'resolved_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $entities
     */
    public function raiseOnce(
        string $code,
        string $domain,
        string $severity,
        ?float $amountDelta = null,
        array $entities = [],
    ): ReconciliationException {
        $fingerprint = hash('sha256', $code.'|'.$domain.'|'.json_encode($entities, JSON_THROW_ON_ERROR));

        $existing = ReconciliationException::query()
            ->open()
            ->where('exception_code', $code)
            ->get()
            ->first(fn (ReconciliationException $row): bool => $this->fingerprint($row) === $fingerprint);

        if ($existing) {
            return $existing;
        }

        return $this->raise($code, $domain, $severity, $amountDelta, $entities);
    }

    /**
     * @param  array<string, mixed>  $entities
     */
    public function raise(
        string $code,
        string $domain,
        string $severity,
        ?float $amountDelta = null,
        array $entities = [],
    ): ReconciliationException {
        $sla = match ($severity) {
            'critical' => now(),
            'high' => now()->endOfDay(),
            'medium' => now()->addHours(48),
            default => now()->addDays(7),
        };

        return ReconciliationException::create([
            'exception_code' => $code,
            'domain' => $domain,
            'severity' => $severity,
            'amount_delta' => $amountDelta,
            'affected_entities' => $entities,
            'status' => ReconciliationException::STATUS_OPEN,
            'sla_deadline' => $sla,
            'raised_at' => now(),
        ]);
    }

    protected function fingerprint(ReconciliationException $exception): string
    {
        return hash('sha256', $exception->exception_code.'|'.$exception->domain.'|'.json_encode(
            $exception->affected_entities ?? [],
            JSON_THROW_ON_ERROR,
        ));
    }
}
