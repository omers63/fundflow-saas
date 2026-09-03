<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\SmsTransaction;
use App\Models\Tenant\Transaction;
use App\Support\BusinessDay;
use App\Support\ContributionPolicySettings;
use InvalidArgumentException;

/**
 * Manual corrective journals for reconciliation exceptions (§5.10).
 */
class ReconciliationCorrectionService
{
    public const ACTION_MANUAL_CORRECTION = 'manual_correction';

    public const ACTION_REVERSED = 'reversed';

    public function __construct(
        protected AccountingService $accounting,
        protected FundPostingService $fundPostings,
        protected FundAuditLogService $audit,
        protected ReconciliationSuspenseService $suspense,
        protected ContributionCollectionCycleService $contributionCollection,
        protected BankClearingMatchService $bankMatching,
        protected SmsBankClearingMatchService $smsBankMatching,
        protected SmsOperationalClearingMatchService $smsOpsMatching,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function postCorrection(ReconciliationException $exception, string $type, array $data): void
    {
        match ($type) {
            'member_cash_credit', 'member_cash_debit' => $this->postMemberCashCorrection(
                $exception,
                Member::query()->findOrFail((int) $data['member_id']),
                str_ends_with($type, 'credit') ? 'credit' : 'debit',
                (float) $data['amount'],
                (string) $data['reason'],
            ),
            'member_fund_principal' => $this->postContributionPrincipalCorrection(
                $exception,
                (int) $data['contribution_id'],
                (string) $data['reason'],
            ),
            'late_fee_tier' => $this->reapplyLateFeeTier(
                $exception,
                (int) $data['contribution_id'],
                (string) $data['reason'],
            ),
            'emi_overpayment_refund' => $this->postEmiOverpaymentRefund(
                $exception,
                Loan::query()->findOrFail((int) $data['loan_id']),
                (float) $data['amount'],
                (string) $data['reason'],
            ),
            'custom_journal' => $this->postCustomJournal(
                $exception,
                (array) ($data['legs'] ?? []),
                (string) $data['reason'],
            ),
            default => throw new InvalidArgumentException(__('Unknown correction type.')),
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $legs
     * @return list<Transaction>
     */
    public function postCustomJournal(
        ReconciliationException $exception,
        array $legs,
        string $reason,
    ): array {
        $trimmed = trim($reason);

        if ($trimmed === '') {
            throw new InvalidArgumentException(__('A reason is required for a correction.'));
        }

        $description = __('RECON_MANUAL_CORRECTION — :reason', ['reason' => $trimmed]);

        $transactions = $this->accounting->postBalancedJournal($legs, $description, $exception);

        $this->audit->log('RECON_MANUAL_CORRECTION', 'reconciliation', $exception, null, [
            'action' => 'custom_journal',
            'leg_count' => count($transactions),
            'transaction_ids' => collect($transactions)->pluck('id')->all(),
            'reason' => $trimmed,
        ]);

        $exception->update([
            'resolution_action' => self::ACTION_MANUAL_CORRECTION,
            'resolution_notes' => $trimmed,
        ]);

        return $transactions;
    }

    /**
     * @return array{reversal_count: int, reversal_transaction_id: ?int}
     */
    public function reverseLinkedTransaction(
        ReconciliationException $exception,
        int $transactionId,
        string $reason,
        bool $fullSource = false,
    ): array {
        $transaction = Transaction::query()->with('account')->find($transactionId);

        if ($transaction === null) {
            throw new InvalidArgumentException(__('Transaction was not found.'));
        }

        $trimmed = trim($reason);

        if ($trimmed === '') {
            throw new InvalidArgumentException(__('A reason is required for a reversal.'));
        }

        $count = 0;
        $reversalId = null;

        if ($fullSource && $this->accounting->canUseFullSourceReversal($transaction)) {
            $count = $this->accounting->createFullSourceReversal($transaction, $trimmed);
        } else {
            $reversal = $this->accounting->createReversalEntry($transaction, $trimmed);
            $count = 1;
            $reversalId = $reversal->id;
        }

        $this->audit->log('RECON_MANUAL_CORRECTION', 'reconciliation', $exception, $transaction->member, [
            'action' => 'reverse',
            'transaction_id' => $transactionId,
            'reversal_count' => $count,
            'reason' => $trimmed,
        ]);

        $exception->update([
            'resolution_action' => self::ACTION_REVERSED,
            'resolution_notes' => $trimmed,
        ]);

        return ['reversal_count' => $count, 'reversal_transaction_id' => $reversalId];
    }

    public function postMemberCashCorrection(
        ReconciliationException $exception,
        Member $member,
        string $direction,
        float $amount,
        string $reason,
    ): Transaction {
        if ($amount <= 0.00001) {
            throw new InvalidArgumentException(__('Amount must be greater than zero.'));
        }

        $cash = $member->cashAccount;

        if ($cash === null) {
            throw new InvalidArgumentException(__('Member cash account is not configured.'));
        }

        $trimmed = trim($reason);

        if ($trimmed === '') {
            throw new InvalidArgumentException(__('A reason is required for a correction.'));
        }

        $description = __('RECON_MANUAL_CORRECTION — :reason', ['reason' => $trimmed]);

        $transaction = match ($direction) {
            'credit' => $this->accounting->creditMemberCashWithMasterMirror(
                $cash,
                $amount,
                $description,
                __('(recon correction mirror)'),
                $exception,
                null,
                $member->id,
            ),
            'debit' => $this->accounting->debitMemberCashWithMasterMirror(
                $cash,
                $amount,
                $description,
                __('(recon correction mirror)'),
                $exception,
                null,
                $member->id,
            ),
            default => throw new InvalidArgumentException(__('Direction must be credit or debit.')),
        };

        $this->audit->log('RECON_MANUAL_CORRECTION', 'reconciliation', $exception, $member, [
            'action' => 'member_cash_'.$direction,
            'amount' => $amount,
            'transaction_id' => $transaction->id,
            'reason' => $trimmed,
        ]);

        $exception->update([
            'resolution_action' => self::ACTION_MANUAL_CORRECTION,
            'resolution_notes' => $trimmed,
        ]);

        return $transaction;
    }

    public function resolveAmbiguousBankMatch(
        ReconciliationException $exception,
        int $importedBankTransactionId,
        int $unclearedBankTransactionId,
        string $notes,
    ): void {
        $imported = BankTransaction::query()->find($importedBankTransactionId);
        $uncleared = BankTransaction::query()->find($unclearedBankTransactionId);

        if ($imported === null || $uncleared === null) {
            throw new InvalidArgumentException(__('One or both bank transactions were not found.'));
        }

        if ($uncleared->is_cleared) {
            throw new InvalidArgumentException(__('The selected pending entry is already cleared.'));
        }

        if (! $this->bankMatching->isPendingClearance($uncleared)) {
            throw new InvalidArgumentException(__('The selected pending entry is not eligible for bank clearance.'));
        }

        if (! $this->bankMatching->isImportedMatchCandidate($imported)) {
            throw new InvalidArgumentException(__('The selected bank import line is not eligible for matching.'));
        }

        $tolerance = ContributionPolicySettings::reconTolerance();

        if (abs((float) $imported->amount - (float) $uncleared->amount) > $tolerance) {
            throw new InvalidArgumentException(__('Bank line amounts do not match within tolerance.'));
        }

        $this->bankMatching->clearMatchPair($uncleared, $imported);

        $this->audit->log('RECON_MANUAL_CORRECTION', 'reconciliation', $exception, null, [
            'action' => 'ambiguous_bank_match',
            'imported_id' => $imported->id,
            'uncleared_id' => $uncleared->id,
            'notes' => $notes,
        ]);

        $exception->update([
            'status' => ReconciliationException::STATUS_RESOLVED,
            'resolution_action' => self::ACTION_MANUAL_CORRECTION,
            'resolution_notes' => $notes,
            'resolved_at' => BusinessDay::now(),
        ]);
    }

    /**
     * @param  list<int>  $importedBankTransactionIds
     * @param  list<int>  $unclearedBankTransactionIds
     */
    public function resolveSplittableBankMatch(
        ReconciliationException $exception,
        array $importedBankTransactionIds,
        array $unclearedBankTransactionIds,
        string $notes,
    ): void {
        $imported = BankTransaction::query()
            ->whereIn('id', $importedBankTransactionIds)
            ->get();
        $operational = BankTransaction::query()
            ->whereIn('id', $unclearedBankTransactionIds)
            ->get();

        if ($imported->isEmpty() || $operational->isEmpty()) {
            throw new InvalidArgumentException(__('Select at least one operational row and one bank import line.'));
        }

        foreach ($operational as $row) {
            if (! $this->bankMatching->isPendingClearance($row)) {
                throw new InvalidArgumentException(__('One or more operational rows are not eligible for clearance.'));
            }
        }

        foreach ($imported as $row) {
            if (! $this->bankMatching->isImportedMatchCandidate($row)) {
                throw new InvalidArgumentException(__('One or more bank import lines are not eligible for matching.'));
            }
        }

        if (! $this->bankMatching->groupAmountsMatch($operational, $imported)) {
            throw new InvalidArgumentException(__('Selected amounts do not balance within tolerance.'));
        }

        $this->bankMatching->clearMatchGroup($operational, $imported);

        $this->audit->log('RECON_MANUAL_CORRECTION', 'reconciliation', $exception, null, [
            'action' => 'splittable_bank_match',
            'imported_ids' => $imported->pluck('id')->all(),
            'uncleared_ids' => $operational->pluck('id')->all(),
            'notes' => $notes,
        ]);

        $exception->update([
            'status' => ReconciliationException::STATUS_RESOLVED,
            'resolution_action' => self::ACTION_MANUAL_CORRECTION,
            'resolution_notes' => $notes,
            'resolved_at' => BusinessDay::now(),
        ]);
    }

    public function assignMemberAndPostSms(
        ReconciliationException $exception,
        int $smsTransactionId,
        int $memberId,
        string $notes,
    ): void {
        $tx = SmsTransaction::query()->find($smsTransactionId);
        $member = Member::query()->find($memberId);

        if ($tx === null || $member === null) {
            throw new InvalidArgumentException(__('The SMS row or member was not found.'));
        }

        if ($tx->isPosted()) {
            throw new InvalidArgumentException(__('This SMS row is already posted.'));
        }

        if ($tx->is_duplicate) {
            throw new InvalidArgumentException(__('Duplicate SMS rows cannot be posted.'));
        }

        $tx->update(['member_id' => $member->id]);
        $this->accounting->postSmsTransactionToCash($tx->fresh() ?? $tx, $member);

        $this->audit->log('RECON_MANUAL_CORRECTION', 'reconciliation', $exception, $member, [
            'action' => 'assign_member_and_post_sms',
            'sms_transaction_id' => $tx->id,
            'member_id' => $member->id,
            'notes' => $notes,
        ]);

        $exception->update([
            'status' => ReconciliationException::STATUS_RESOLVED,
            'resolution_action' => self::ACTION_MANUAL_CORRECTION,
            'resolution_notes' => $notes,
            'resolved_at' => BusinessDay::now(),
        ]);
    }

    public function reverseSmsPost(
        ReconciliationException $exception,
        int $smsTransactionId,
        string $notes,
    ): void {
        $tx = SmsTransaction::query()->find($smsTransactionId);

        if ($tx === null) {
            throw new InvalidArgumentException(__('The SMS row was not found.'));
        }

        if (! $tx->isPosted()) {
            throw new InvalidArgumentException(__('This SMS row is not posted.'));
        }

        $this->accounting->reverseSmsTransactionPost($tx, $notes);

        $this->audit->log('RECON_MANUAL_CORRECTION', 'reconciliation', $exception, $tx->member, [
            'action' => 'reverse_sms_post',
            'sms_transaction_id' => $tx->id,
            'notes' => $notes,
        ]);

        $exception->update([
            'status' => ReconciliationException::STATUS_RESOLVED,
            'resolution_action' => self::ACTION_MANUAL_CORRECTION,
            'resolution_notes' => $notes,
            'resolved_at' => BusinessDay::now(),
        ]);
    }

    public function resolveSmsBankMatch(
        ReconciliationException $exception,
        int $smsTransactionId,
        int $bankTransactionId,
        string $notes,
    ): void {
        $sms = SmsTransaction::query()->find($smsTransactionId);
        $bank = BankTransaction::query()->find($bankTransactionId);

        if ($sms === null || $bank === null) {
            throw new InvalidArgumentException(__('The SMS row or bank line was not found.'));
        }

        $this->smsBankMatching->clearMatchPair($sms, $bank);

        $this->audit->log('RECON_MANUAL_CORRECTION', 'reconciliation', $exception, $sms->member, [
            'action' => 'sms_bank_match',
            'sms_transaction_id' => $sms->id,
            'bank_transaction_id' => $bank->id,
            'notes' => $notes,
        ]);

        $exception->update([
            'status' => ReconciliationException::STATUS_RESOLVED,
            'resolution_action' => self::ACTION_MANUAL_CORRECTION,
            'resolution_notes' => $notes,
            'resolved_at' => BusinessDay::now(),
        ]);
    }

    /**
     * @param  list<int>  $smsTransactionIds
     * @param  list<int>  $bankTransactionIds
     */
    public function resolveSmsBankMatchGroup(
        ReconciliationException $exception,
        array $smsTransactionIds,
        array $bankTransactionIds,
        string $notes,
    ): void {
        $smsRows = SmsTransaction::query()->whereIn('id', $smsTransactionIds)->get();
        $bankRows = BankTransaction::query()->whereIn('id', $bankTransactionIds)->get();

        if ($smsRows->isEmpty() || $bankRows->isEmpty()) {
            throw new InvalidArgumentException(__('Select at least one SMS row and one bank import line.'));
        }

        $this->smsBankMatching->clearMatchGroup($smsRows, $bankRows);

        $this->audit->log('RECON_MANUAL_CORRECTION', 'reconciliation', $exception, null, [
            'action' => 'sms_bank_match_group',
            'sms_transaction_ids' => $smsRows->pluck('id')->all(),
            'bank_transaction_ids' => $bankRows->pluck('id')->all(),
            'notes' => $notes,
        ]);

        $exception->update([
            'status' => ReconciliationException::STATUS_RESOLVED,
            'resolution_action' => self::ACTION_MANUAL_CORRECTION,
            'resolution_notes' => $notes,
            'resolved_at' => BusinessDay::now(),
        ]);
    }

    public function resolveSmsOpsMatch(
        ReconciliationException $exception,
        int $operationalBankTransactionId,
        int $smsTransactionId,
        string $notes,
    ): void {
        $ops = BankTransaction::query()->find($operationalBankTransactionId);
        $sms = SmsTransaction::query()->find($smsTransactionId);

        if ($ops === null || $sms === null) {
            throw new InvalidArgumentException(__('The operational row or SMS row was not found.'));
        }

        $this->smsOpsMatching->clearMatchPair($ops, $sms);

        $this->audit->log('RECON_MANUAL_CORRECTION', 'reconciliation', $exception, $sms->member, [
            'action' => 'sms_ops_match',
            'operational_bank_transaction_id' => $ops->id,
            'sms_transaction_id' => $sms->id,
            'notes' => $notes,
        ]);

        $exception->update([
            'status' => ReconciliationException::STATUS_RESOLVED,
            'resolution_action' => self::ACTION_MANUAL_CORRECTION,
            'resolution_notes' => $notes,
            'resolved_at' => BusinessDay::now(),
        ]);
    }

    public function postMasterBankClearingAdjustment(
        ReconciliationException $exception,
        float $amount,
        string $reason,
    ): void {
        if ($amount <= 0.00001) {
            throw new InvalidArgumentException(__('Amount must be greater than zero.'));
        }

        $masterBank = Account::masterBank();
        $masterCash = Account::masterCash();

        if ($masterBank === null || $masterCash === null) {
            throw new InvalidArgumentException(__('Master bank and cash accounts are not configured.'));
        }

        $trimmed = trim($reason);
        $description = __('RECON_MANUAL_CORRECTION — bank clearing — :reason', ['reason' => $trimmed]);

        $this->accounting->debit($masterBank, $amount, $description, $exception);
        $this->accounting->credit($masterCash, $amount, $description, $exception);

        $this->audit->log('RECON_MANUAL_CORRECTION', 'reconciliation', $exception, null, [
            'action' => 'bank_clearing',
            'amount' => $amount,
            'reason' => $trimmed,
        ]);

        $exception->update([
            'resolution_action' => self::ACTION_MANUAL_CORRECTION,
            'resolution_notes' => $trimmed,
        ]);
    }

    /**
     * Post RECON_EMI_OVERPAYMENT_REFUND journal (CR member cash, DR master fund).
     */
    public function postEmiOverpaymentRefund(
        ReconciliationException $exception,
        Loan $loan,
        float $amount,
        string $reason,
    ): void {
        if ($amount <= 0.00001) {
            throw new InvalidArgumentException(__('Refund amount must be greater than zero.'));
        }

        $loan->loadMissing('member.cashAccount', 'member.fundAccount');
        $memberCash = $loan->member->cashAccount;

        if ($memberCash === null) {
            throw new InvalidArgumentException(__('Member cash account is required.'));
        }

        $trimmed = trim($reason);
        $description = __('RECON_EMI_OVERPAYMENT_REFUND — loan #:id — :reason', [
            'id' => $loan->id,
            'reason' => $trimmed,
        ]);

        $memberFund = $loan->member->fundAccount;

        if ($memberFund === null) {
            throw new InvalidArgumentException(__('Member fund account is not configured.'));
        }

        $this->accounting->creditMemberCashWithMasterMirror(
            $memberCash,
            $amount,
            $description,
            __('(EMI overpayment refund mirror)'),
            $loan,
            null,
            $loan->member_id,
        );
        $this->accounting->debitMemberFundWithMasterMirror(
            $memberFund,
            $amount,
            $description,
            __('(EMI overpayment refund mirror)'),
            $loan,
            null,
            $loan->member_id,
        );

        $overRepaid = max(0.0, (float) $loan->repaid_to_master - (float) $loan->master_portion);

        if ($overRepaid > 0.00001) {
            $loan->decrement('repaid_to_master', min($amount, $overRepaid));
        }

        $this->audit->log('RECON_MANUAL_CORRECTION', 'reconciliation', $exception, $loan->member, [
            'action' => 'emi_overpayment_refund',
            'loan_id' => $loan->id,
            'amount' => $amount,
            'reason' => $trimmed,
        ]);

        $exception->update([
            'resolution_action' => self::ACTION_MANUAL_CORRECTION,
            'resolution_notes' => $trimmed,
        ]);
    }

    public function postContributionPrincipalCorrection(
        ReconciliationException $exception,
        int $contributionId,
        string $reason,
    ): void {
        $contribution = Contribution::query()->with('member.fundAccount')->findOrFail($contributionId);
        $masterFund = Account::masterFund();
        $memberFund = $contribution->member?->fundAccount;

        if ($masterFund === null || $memberFund === null) {
            throw new InvalidArgumentException(__('Fund accounts are not configured.'));
        }

        $amount = (float) ($contribution->amount_collected ?? $contribution->amount);
        $trimmed = trim($reason);
        $periodLabel = $contribution->period?->format('M Y') ?? '';
        $description = __('RECON_MANUAL_CORRECTION — contribution — :period — :reason', [
            'period' => $periodLabel,
            'reason' => $trimmed,
        ]);

        $this->accounting->creditMemberFundWithMasterMirror(
            $memberFund,
            $amount,
            $description,
            __('(recon correction mirror)'),
            $contribution,
        );

        $this->audit->log('RECON_MANUAL_CORRECTION', 'reconciliation', $exception, $contribution->member, [
            'action' => 'member_fund_principal',
            'contribution_id' => $contributionId,
            'amount' => $amount,
            'reason' => $trimmed,
        ]);

        $exception->update([
            'resolution_action' => self::ACTION_MANUAL_CORRECTION,
            'resolution_notes' => $trimmed,
        ]);
    }

    public function reapplyLateFeeTier(
        ReconciliationException $exception,
        int $contributionId,
        string $reason,
    ): void {
        $contribution = Contribution::query()->findOrFail($contributionId);

        if (! $this->contributionCollection->applyLateFeeTierForContribution($contribution)) {
            throw new InvalidArgumentException(__('Late fee tier is already correct or contribution is not overdue.'));
        }

        $trimmed = trim($reason);

        $this->audit->log('RECON_MANUAL_CORRECTION', 'reconciliation', $exception, $contribution->member, [
            'action' => 'late_fee_tier',
            'contribution_id' => $contributionId,
            'reason' => $trimmed,
        ]);

        $exception->update([
            'resolution_action' => self::ACTION_MANUAL_CORRECTION,
            'resolution_notes' => $trimmed,
        ]);
    }
}
