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
use App\Models\Tenant\MigrationCycleStub;
use App\Models\Tenant\MigrationInstalmentSchedule;
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
        $raised += $this->reconcileMigration();
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

        $member = $transaction->member;

        if ($member && $member->migration_status === 'migration_pending') {
            $allowed = str_starts_with((string) $transaction->description, 'MIGRATION_')
                || str_contains((string) $transaction->description, 'Migration');

            if (! $allowed) {
                $this->raiseOnce('INELIGIBLE_ACCOUNT_POSTING', 'migration', 'high', null, [
                    'transaction_id' => $transaction->id,
                    'member_id' => $member->id,
                ]);

                $this->attemptVoidUnbalancedOrIneligible($transaction, __('Ineligible posting on migration-pending member'));
            }
        }

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
    }

    protected function handleRealtimeMemberInvariant(Transaction $transaction): void
    {
        $member = $transaction->member;

        if ($member === null || $member->migration_status === 'migration_pending') {
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

        if ($member->migration_status === 'migration_pending') {
            $this->raiseOnce('RECON_AUTO_FEE_EXEMPTION_REVERSAL', 'late_fee', 'low', (float) $transaction->amount, [
                'transaction_id' => $transaction->id,
                'member_id' => $member->id,
                'reason' => 'migration_pending',
            ]);

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
            ->whereHas('member', fn ($q) => $q->where('migration_status', 'migration_pending'))
            ->with('member')
            ->each(function (Contribution $contribution) use (&$count): void {
                $this->raiseOnce('MIGRATION_PENDING_DEBITED', 'contribution', 'high', null, [
                    'contribution_id' => $contribution->id,
                    'member_id' => $contribution->member_id,
                ]);
                $count++;
            });

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
            ->where(function ($query): void {
                $query->whereNotNull('fund_posting_id')
                    ->orWhereNotNull('membership_application_id');
            })
            ->where('created_at', '<', now()->subDays($staleDays))
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

        Contribution::query()
            ->where('status', 'pending')
            ->where('late_fee_amount', '>', 0)
            ->whereHas('member', fn ($q) => $q->where('migration_status', 'migration_pending'))
            ->each(function (Contribution $contribution) use (&$count): void {
                $this->raiseOnce('MIGRATION_LATE_FEE_APPLIED', 'late_fee', 'medium', (float) $contribution->late_fee_amount, [
                    'contribution_id' => $contribution->id,
                ]);
                $count++;
            });

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

                $wrongAccount = match ($transaction->type) {
                    'credit' => ! $account->is_master || $account->type !== 'fees',
                    'debit' => $account->is_master || $account->type !== 'cash',
                    default => false,
                };

                if ($wrongAccount) {
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

    protected function reconcileMemberInvariants(): int
    {
        $count = 0;
        $tolerance = ContributionPolicySettings::reconTolerance();

        Member::query()
            ->where('status', 'active')
            ->where('migration_status', '!=', 'migration_pending')
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

    protected function reconcileMigration(): int
    {
        $count = 0;

        Member::query()
            ->where('migration_status', 'active')
            ->whereNull('partial_clearance_granted_at')
            ->whereHas('migrationStubs', fn ($q) => $q->unresolved())
            ->each(function (Member $member) use (&$count): void {
                $this->raiseOnce('ACTIVE_WITH_UNRESOLVED_STUBS', 'migration', 'high', null, [
                    'member_id' => $member->id,
                ]);
                $count++;
            });

        Member::query()
            ->whereNotNull('partial_clearance_granted_at')
            ->whereHas('migrationStubs', fn ($q) => $q
                ->where('classification', MigrationCycleStub::CLASS_ESCALATED)
                ->where('status', '!=', 'closed'))
            ->each(function (Member $member) use (&$count): void {
                $this->raiseOnce('PARTIAL_CLEARANCE_ESCALATED_OPEN', 'migration', 'low', null, [
                    'member_id' => $member->id,
                ]);
                $count++;
            });

        Member::query()
            ->where('migration_status', 'active')
            ->whereHas('migrationStubs', fn ($q) => $q
                ->where('classification', MigrationCycleStub::CLASS_BACKDATED_DUE)
                ->whereNull('resolution_method'))
            ->each(function (Member $member) use (&$count): void {
                $this->raiseOnce('BACKDATED_DUE_UNRESOLVED', 'migration', 'high', null, [
                    'member_id' => $member->id,
                ]);
                $count++;
            });

        Member::query()
            ->whereNotNull('opening_balances_posted_at')
            ->each(function (Member $member) use (&$count): void {
                $cashOpening = (float) ($member->opening_cash_balance ?? 0);
                $fundOpening = (float) ($member->opening_fund_balance ?? 0);

                if ($cashOpening > 0.00001) {
                    $cashLegs = Transaction::query()
                        ->where('member_id', $member->id)
                        ->where('description', 'like', 'MIGRATION_OPENING — cash%')
                        ->count();

                    if ($cashLegs < 2) {
                        $this->raiseOnce('MIGRATION_OPENING_MISSING_LEG', 'migration', 'high', null, [
                            'member_id' => $member->id,
                            'leg' => 'cash',
                        ]);
                        $count++;
                    }
                }

                if ($fundOpening > 0.00001) {
                    $fundLegs = Transaction::query()
                        ->where('member_id', $member->id)
                        ->where('description', 'like', 'MIGRATION_OPENING — fund%')
                        ->count();

                    if ($fundLegs < 2) {
                        $this->raiseOnce('MIGRATION_OPENING_MISSING_LEG', 'migration', 'high', null, [
                            'member_id' => $member->id,
                            'leg' => 'fund',
                        ]);
                        $count++;
                    }
                }
            });

        $tolerance = ContributionPolicySettings::reconTolerance();
        $masterFundId = Account::masterFund()?->id;

        if ($masterFundId !== null) {
            $postedOpeningFund = (float) Transaction::query()
                ->where('account_id', $masterFundId)
                ->where('type', 'credit')
                ->where('description', 'like', 'MIGRATION_OPENING — fund%')
                ->sum('amount');

            $expectedOpeningFund = (float) Member::query()
                ->whereNotNull('opening_balances_posted_at')
                ->sum('opening_fund_balance');

            if (abs($postedOpeningFund - $expectedOpeningFund) > $tolerance) {
                $this->raiseOnce('MIGRATION_OPENING_SUM_DRIFT', 'migration', 'high', $postedOpeningFund - $expectedOpeningFund, [
                    'posted_sum' => $postedOpeningFund,
                    'expected_sum' => $expectedOpeningFund,
                ]);
                $count++;
            }
        }

        Member::query()
            ->whereNotNull('opening_balances_posted_at')
            ->whereNull('migration_cutoff_date')
            ->each(function (Member $member) use (&$count): void {
                $this->raiseOnce('MIGRATION_CUTOFF_MISSING', 'migration', 'medium', null, [
                    'member_id' => $member->id,
                ]);
                $count++;
            });

        Member::query()
            ->whereNotNull('opening_balances_posted_at')
            ->each(function (Member $member) use (&$count): void {
                $member->loadMissing('fundAccount');
                $balance = (float) ($member->fundAccount?->balance ?? 0);

                if ($balance >= -0.00001) {
                    return;
                }

                $hasObOffset = Transaction::query()
                    ->where('member_id', $member->id)
                    ->where('description', 'like', 'MIGRATION_OB_OFFSET%')
                    ->exists();

                if ($hasObOffset) {
                    $this->raiseOnce('OB_OFFSET_NEGATIVE_FUND', 'migration', 'high', $balance, [
                        'member_id' => $member->id,
                    ]);
                    $count++;
                }
            });

        Member::query()
            ->whereHas('migrationInstalmentSchedules')
            ->each(function (Member $member) use (&$count, $tolerance): void {
                $scheduleTotal = (float) MigrationInstalmentSchedule::query()
                    ->where('member_id', $member->id)
                    ->sum('amount');

                $backdatedTotal = (float) MigrationCycleStub::query()
                    ->where('member_id', $member->id)
                    ->where('classification', MigrationCycleStub::CLASS_BACKDATED_DUE)
                    ->sum('amount_due');

                if ($scheduleTotal > $backdatedTotal + $tolerance && $backdatedTotal > 0.00001) {
                    $this->raiseOnce('MIGRATION_INSTALMENT_EXCESS', 'migration', 'medium', $scheduleTotal - $backdatedTotal, [
                        'member_id' => $member->id,
                        'schedule_total' => $scheduleTotal,
                        'backdated_total' => $backdatedTotal,
                    ]);
                    $count++;
                }
            });

        Contribution::query()
            ->where('status', 'posted')
            ->whereNotNull('period')
            ->each(function (Contribution $contribution) use (&$count): void {
                $period = $contribution->period;

                $waivedStub = MigrationCycleStub::query()
                    ->where('member_id', $contribution->member_id)
                    ->where('classification', MigrationCycleStub::CLASS_WAIVED)
                    ->whereYear('cycle_date', (int) $period->year)
                    ->whereMonth('cycle_date', (int) $period->month)
                    ->exists();

                if ($waivedStub) {
                    $this->raiseOnce('WAIVED_CYCLE_DEBITED', 'migration', 'high', (float) $contribution->amount, [
                        'contribution_id' => $contribution->id,
                        'member_id' => $contribution->member_id,
                        'period' => $period->format('Y-m'),
                    ]);
                    $count++;
                }
            });

        $count += $this->reconcileMigrationLedgerIntegrity($tolerance);

        return $count;
    }

    protected function reconcileMigrationLedgerIntegrity(float $tolerance): int
    {
        $count = 0;
        $masterFundId = Account::masterFund()?->id;

        if ($masterFundId === null) {
            return 0;
        }

        $expectedObligation = (float) Member::query()
            ->whereNotNull('opening_balances_posted_at')
            ->sum('opening_fund_balance')
            + (float) MigrationCycleStub::query()
                ->where('classification', MigrationCycleStub::CLASS_BACKDATED_DUE)
                ->sum('amount_due');

        $openingPosted = (float) Transaction::query()
            ->where('account_id', $masterFundId)
            ->where('type', 'credit')
            ->where('description', 'like', 'MIGRATION_OPENING — fund%')
            ->sum('amount');

        $lumpsumPosted = (float) Transaction::query()
            ->where('account_id', $masterFundId)
            ->where('type', 'credit')
            ->where('description', 'like', 'MIGRATION_LUMPSUM%')
            ->sum('amount');

        $obOffsetApplied = (float) Transaction::query()
            ->where('account_id', $masterFundId)
            ->where('type', 'credit')
            ->where('description', 'like', 'MIGRATION_OB_OFFSET%')
            ->sum('amount');

        $ledgerTotal = $openingPosted + $lumpsumPosted + $obOffsetApplied;

        if ($expectedObligation > 0.00001 && abs($ledgerTotal - $expectedObligation) > $tolerance) {
            $this->raiseOnce('MIGRATION_LEDGER_DRIFT', 'migration', 'high', $ledgerTotal - $expectedObligation, [
                'ledger_total' => $ledgerTotal,
                'expected_obligation' => $expectedObligation,
            ]);
            $count++;
        }

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

        if ($exception->exception_code === 'MIGRATION_LATE_FEE_APPLIED') {
            return $this->autoReverseMigrationLateFee($exception);
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

        if ($exception->exception_code === 'MIGRATION_PENDING_DEBITED') {
            return $this->autoReverseMigrationPendingContribution($exception);
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

        if ($exception->exception_code === 'PARTIAL_CLEARANCE_ESCALATED_OPEN') {
            $this->resolveException($exception, __('Acknowledged under partial clearance — monitoring'));

            return true;
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

        if ($exception->exception_code === 'WAIVED_CYCLE_DEBITED') {
            return $this->autoReverseWaivedCycleContribution($exception);
        }

        if ($exception->exception_code === 'MIGRATION_INSTALMENT_EXCESS') {
            return $this->autoRefundMigrationInstalmentExcess($exception);
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
            $this->accounting->credit($memberFund, $amount, $description, $contribution);
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

    protected function autoReverseWaivedCycleContribution(ReconciliationException $exception): bool
    {
        $contributionId = (int) ($exception->affected_entities['contribution_id'] ?? 0);
        $exception->update([
            'affected_entities' => array_merge($exception->affected_entities ?? [], [
                'contribution_id' => $contributionId,
            ]),
        ]);

        return $this->autoReverseExemptContribution($exception);
    }

    protected function autoRefundMigrationInstalmentExcess(ReconciliationException $exception): bool
    {
        $memberId = (int) ($exception->affected_entities['member_id'] ?? 0);
        $member = Member::query()->with('cashAccount')->find($memberId);
        $masterFund = Account::masterFund();
        $excess = (float) ($exception->amount_delta ?? 0);

        if ($member === null || $masterFund === null || $excess <= 0.00001) {
            return false;
        }

        $instalmentAmount = (float) MigrationInstalmentSchedule::query()
            ->where('member_id', $memberId)
            ->orderByDesc('amount')
            ->value('amount');

        if ($instalmentAmount > 0.00001 && $excess > $instalmentAmount + ContributionPolicySettings::reconTolerance()) {
            return false;
        }

        $cash = $member->cashAccount;

        if ($cash === null) {
            return false;
        }

        try {
            $description = __('RECON_MIGRATION_INSTALMENT_EXCESS_REFUND — :name', ['name' => $member->name]);
            $this->accounting->credit($cash, $excess, $description, $exception, null, $member->id);
            $this->accounting->debit($masterFund, $excess, $description, $exception, null, $member->id);
            $this->resolveException($exception, __('Refunded migration instalment plan excess'), true);

            return true;
        } catch (\Throwable) {
            return false;
        }
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
            $this->accounting->credit($masterFund, $amount, $description, $contribution);
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

    protected function autoReverseMigrationPendingContribution(ReconciliationException $exception): bool
    {
        return $this->autoReverseExemptContribution($exception);
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

    protected function autoReverseMigrationLateFee(ReconciliationException $exception): bool
    {
        $contributionId = (int) ($exception->affected_entities['contribution_id'] ?? 0);
        $contribution = Contribution::query()->find($contributionId);

        if ($contribution === null || (float) ($contribution->late_fee_amount ?? 0) <= 0.00001) {
            return false;
        }

        try {
            DB::transaction(function () use ($contribution): void {
                $late = (float) $contribution->late_fee_amount;
                $this->accounting->reverseContributionLateFee($contribution, $late);
                $contribution->update([
                    'late_fee_amount' => null,
                    'late_fee_tier' => null,
                    'collection_status' => ContributionCollectionStatus::OVERDUE,
                ]);
            });

            $this->resolveException($exception, __('Reversed late fee on migration-pending member'));

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
