<?php

namespace App\Services;

use App\Exceptions\InsufficientMemberCashForCollectionException;
use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Support\ContributionPolicySettings;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class AccountingService
{
    private static int $memberCashCollectionDepth = 0;

    private static bool $memberCashSettlementActive = false;

    private static int $masterPoolMirrorDepth = 0;

    /**
     * Run a callback without dispatching member cash collection (for tests and internal transfers).
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutMemberCashCollection(callable $callback): mixed
    {
        self::$memberCashCollectionDepth++;

        try {
            return $callback();
        } finally {
            self::$memberCashCollectionDepth--;
        }
    }

    public static function memberCashCollectionInProgress(): bool
    {
        return self::$memberCashSettlementActive;
    }

    public static function masterPoolMirrorInProgress(): bool
    {
        return self::$masterPoolMirrorDepth > 0;
    }

    /**
     * Run a callback without auto-mirroring member cash/fund legs to master pool accounts.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutMasterPoolMirror(callable $callback): mixed
    {
        self::$masterPoolMirrorDepth++;

        try {
            return $callback();
        } finally {
            self::$masterPoolMirrorDepth--;
        }
    }

    /**
     * Transfer money between two accounts with transaction logging.
     *
     * @param  Account  $from  Source account (debited)
     * @param  Account  $to  Destination account (credited)
     * @param  float  $amount  Amount to transfer
     * @param  string  $description  Human-readable description
     * @param  Model|null  $reference  Polymorphic reference (Contribution, Loan, LoanRepayment)
     */
    public function transfer(
        Account $from,
        Account $to,
        float $amount,
        string $description,
        ?Model $reference = null,
        ?DateTimeInterface $transactedAt = null,
    ): void {
        DB::transaction(function () use ($from, $to, $amount, $description, $reference, $transactedAt): void {
            $this->debit($from, $amount, $description, $reference, $transactedAt);
            $this->credit($to, $amount, $description, $reference, $transactedAt);
        });
    }

    /**
     * Post a balanced multi-leg journal (§5.10 manual correction composer).
     *
     * @param  array<int, array{account_id: int, type: string, amount: float|int|string}>  $legs
     * @return list<Transaction>
     */
    public function postBalancedJournal(
        array $legs,
        string $description,
        ?Model $reference = null,
        ?DateTimeInterface $transactedAt = null,
    ): array {
        if (count($legs) < 2) {
            throw new InvalidArgumentException(__('A journal must have at least two legs.'));
        }

        $trimmed = trim($description);

        if ($trimmed === '') {
            throw new InvalidArgumentException(__('A description is required for the journal.'));
        }

        $tolerance = ContributionPolicySettings::reconTolerance();
        $debitTotal = 0.0;
        $creditTotal = 0.0;
        $normalized = [];

        foreach ($legs as $index => $leg) {
            $accountId = (int) ($leg['account_id'] ?? 0);
            $type = (string) ($leg['type'] ?? '');
            $amount = round((float) ($leg['amount'] ?? 0), 2);

            if ($accountId <= 0) {
                throw new InvalidArgumentException(__('Leg :n requires an account.', ['n' => $index + 1]));
            }

            if (! in_array($type, ['debit', 'credit'], true)) {
                throw new InvalidArgumentException(__('Leg :n type must be debit or credit.', ['n' => $index + 1]));
            }

            if ($amount <= 0) {
                throw new InvalidArgumentException(__('Leg :n amount must be greater than zero.', ['n' => $index + 1]));
            }

            if ($type === 'debit') {
                $debitTotal += $amount;
            } else {
                $creditTotal += $amount;
            }

            $normalized[] = [
                'account_id' => $accountId,
                'type' => $type,
                'amount' => $amount,
            ];
        }

        if (abs($debitTotal - $creditTotal) > $tolerance) {
            throw new InvalidArgumentException(__('Journal is not balanced (debits :debits, credits :credits).', [
                'debits' => number_format($debitTotal, 2),
                'credits' => number_format($creditTotal, 2),
            ]));
        }

        $accountIds = collect($normalized)->pluck('account_id')->unique()->values()->all();
        $accounts = Account::query()->whereIn('id', $accountIds)->get()->keyBy('id');

        if ($accounts->count() !== count($accountIds)) {
            throw new InvalidArgumentException(__('One or more accounts were not found.'));
        }

        return DB::transaction(function () use ($normalized, $trimmed, $reference, $transactedAt, $accounts): array {
            $posted = [];

            foreach ($normalized as $leg) {
                $account = $accounts->get($leg['account_id']);

                if ($account === null) {
                    throw new InvalidArgumentException(__('Account :id was not found.', ['id' => $leg['account_id']]));
                }

                $posted[] = match ($leg['type']) {
                    'debit' => $this->debit($account, $leg['amount'], $trimmed, $reference, $transactedAt),
                    'credit' => $this->credit($account, $leg['amount'], $trimmed, $reference, $transactedAt),
                    default => throw new InvalidArgumentException(__('Invalid leg type.')),
                };
            }

            return $posted;
        });
    }

    /**
     * Credit an account (increase balance).
     */
    public function credit(
        Account $account,
        float $amount,
        string $description,
        ?Model $reference = null,
        ?DateTimeInterface $transactedAt = null,
        ?int $memberId = null,
    ): Transaction {
        $memberId = $this->resolveTransactionMemberId($account, $memberId);

        $transaction = DB::transaction(function () use ($account, $amount, $description, $reference, $transactedAt, $memberId) {
            $account->lockForUpdate();
            $account->refresh();

            $newBalance = (float) $account->balance + $amount;
            $account->update(['balance' => $newBalance]);

            return Transaction::create([
                'account_id' => $account->id,
                'member_id' => $memberId,
                'type' => 'credit',
                'amount' => $amount,
                'balance_after' => $newBalance,
                'reference_type' => $reference ? get_class($reference) : null,
                'reference_id' => $reference?->id,
                'description' => $description,
                'transacted_at' => $transactedAt ?? now(),
            ]);
        });

        $this->dispatchMemberCashIncreasedIfApplicable($account, $memberId);

        return $transaction;
    }

    /**
     * Debit an account (decrease balance).
     */
    public function debit(
        Account $account,
        float $amount,
        string $description,
        ?Model $reference = null,
        ?DateTimeInterface $transactedAt = null,
        ?int $memberId = null,
    ): Transaction {
        $memberId = $this->resolveTransactionMemberId($account, $memberId);

        return DB::transaction(function () use ($account, $amount, $description, $reference, $transactedAt, $memberId) {
            $account->lockForUpdate();
            $account->refresh();

            $this->guardMemberCashDebitDuringAutoCollection($account, $amount);

            $newBalance = (float) $account->balance - $amount;
            $account->update(['balance' => $newBalance]);

            return Transaction::create([
                'account_id' => $account->id,
                'member_id' => $memberId,
                'type' => 'debit',
                'amount' => $amount,
                'balance_after' => $newBalance,
                'reference_type' => $reference ? get_class($reference) : null,
                'reference_id' => $reference?->id,
                'description' => $description,
                'transacted_at' => $transactedAt ?? now(),
            ]);
        });
    }

    /**
     * Update a ledger transaction and reconcile the account balance when amount or type changes.
     *
     * @param  array{description?: string, type?: string, amount?: float|int|string, transacted_at?: mixed, member_id?: int|null}  $data
     */
    public function updateTransaction(Transaction $transaction, array $data): Transaction
    {
        $transaction->loadMissing('account');
        $account = $transaction->account;

        if ($account === null) {
            throw new InvalidArgumentException(__('Transaction has no account.'));
        }

        $description = trim((string) ($data['description'] ?? $transaction->description ?? ''));

        if ($description === '') {
            throw new InvalidArgumentException(__('Description is required.'));
        }

        $type = (string) ($data['type'] ?? $transaction->type);

        if (! in_array($type, ['credit', 'debit'], true)) {
            throw new InvalidArgumentException(__('Type must be credit or debit.'));
        }

        $amount = round((float) ($data['amount'] ?? $transaction->amount), 2);

        if ($amount <= 0) {
            throw new InvalidArgumentException(__('Amount must be greater than zero.'));
        }

        $transactedAt = $data['transacted_at'] ?? $transaction->transacted_at;

        if (! $transactedAt instanceof CarbonInterface) {
            $transactedAt = Carbon::parse($transactedAt);
        }

        $oldType = (string) $transaction->type;
        $oldAmount = round((float) $transaction->amount, 2);
        $balanceAffectingChanged = $oldType !== $type || abs($oldAmount - $amount) >= 0.005;

        $memberId = array_key_exists('member_id', $data)
            ? $this->resolveTransactionMemberId($account, filled($data['member_id']) ? (int) $data['member_id'] : null)
            : $transaction->member_id;

        return DB::transaction(function () use ($transaction, $account, $description, $type, $amount, $transactedAt, $balanceAffectingChanged, $oldType, $oldAmount, $memberId): Transaction {
            $lockedAccount = Account::query()->lockForUpdate()->findOrFail($account->id);

            if ($balanceAffectingChanged) {
                if ($oldType === 'credit') {
                    $lockedAccount->decrement('balance', $oldAmount);
                } else {
                    $lockedAccount->increment('balance', $oldAmount);
                }

                if ($type === 'credit') {
                    $lockedAccount->increment('balance', $amount);
                } else {
                    $lockedAccount->decrement('balance', $amount);
                }

                $lockedAccount->refresh();
            }

            $transaction->update([
                'description' => $description,
                'type' => $type,
                'amount' => $amount,
                'transacted_at' => $transactedAt,
                'member_id' => $memberId,
                'balance_after' => $balanceAffectingChanged
                    ? $lockedAccount->balance
                    : $transaction->balance_after,
            ]);

            if ($balanceAffectingChanged) {
                $this->reconcileAccountLedgerBalances($lockedAccount);
            }

            return $transaction->fresh();
        });
    }

    /**
     * Delete a ledger transaction after reversing its effect on the account balance.
     * Does not adjust paired entries from the same source reference.
     */
    public function deleteTransaction(Transaction $transaction): void
    {
        $transaction->loadMissing('account');
        $account = $transaction->account;

        if ($account === null) {
            throw new InvalidArgumentException(__('Transaction has no account.'));
        }

        $type = (string) $transaction->type;
        $amount = round((float) $transaction->amount, 2);

        DB::transaction(function () use ($transaction, $account, $type, $amount): void {
            $lockedAccount = Account::query()->lockForUpdate()->findOrFail($account->id);

            if ($type === 'credit') {
                $lockedAccount->decrement('balance', $amount);
            } else {
                $lockedAccount->increment('balance', $amount);
            }

            $transaction->delete();

            $this->reconcileAccountLedgerBalances($lockedAccount);
        });
    }

    /**
     * Recompute balance_after on each ledger line from chronological order.
     *
     * Preserves the account's current balance (including opening balance not stored as lines).
     */
    public function reconcileAccountLedgerBalances(Account $account): void
    {
        $account->refresh();

        $transactions = Transaction::query()
            ->where('account_id', $account->id)
            ->orderBy('transacted_at')
            ->orderBy('id')
            ->get();

        $linesNet = 0.0;

        foreach ($transactions as $ledgerLine) {
            $amount = round((float) $ledgerLine->amount, 2);
            $linesNet = $ledgerLine->type === 'credit'
                ? round($linesNet + $amount, 2)
                : round($linesNet - $amount, 2);
        }

        $running = round((float) $account->balance - $linesNet, 2);

        foreach ($transactions as $ledgerLine) {
            $amount = round((float) $ledgerLine->amount, 2);

            if ($ledgerLine->type === 'credit') {
                $running = round($running + $amount, 2);
            } else {
                $running = round($running - $amount, 2);
            }

            if ((float) $ledgerLine->balance_after !== $running) {
                $ledgerLine->update(['balance_after' => $running]);
            }
        }
    }

    public function canSplitTransaction(Transaction $transaction): bool
    {
        if ($this->isReversalEntry($transaction)) {
            return false;
        }

        if ($this->hasExistingReversal($transaction)) {
            return false;
        }

        return round((float) $transaction->amount, 2) > 0;
    }

    /**
     * Replace one ledger entry with labelled parts that sum to the same amount and type.
     * Net balance effect is zero (original removed, then parts re-posted).
     *
     * @param  array<int, array{amount: float|int|string, description: string}>  $parts
     */
    public function splitTransaction(Transaction $original, array $parts): void
    {
        $original->loadMissing('account');
        $account = $original->account;

        if ($account === null) {
            throw new InvalidArgumentException(__('Transaction has no account.'));
        }

        if (! $this->canSplitTransaction($original)) {
            throw new InvalidArgumentException(__('This transaction cannot be split.'));
        }

        $originalAmount = round((float) $original->amount, 2);
        $partTotal = round(array_sum(array_map(
            fn (array $part): float => round((float) ($part['amount'] ?? 0), 2),
            $parts,
        )), 2);

        if (abs($partTotal - $originalAmount) >= 0.005) {
            throw new InvalidArgumentException(__('Parts must sum to the original amount (:amount).', [
                'amount' => number_format($originalAmount, 2),
            ]));
        }

        if (count($parts) < 2) {
            throw new InvalidArgumentException(__('At least two parts are required for a split.'));
        }

        foreach ($parts as $index => $part) {
            $partNumber = $index + 1;

            if (round((float) ($part['amount'] ?? 0), 2) <= 0) {
                throw new InvalidArgumentException(__('Part #:number must have a positive amount.', [
                    'number' => $partNumber,
                ]));
            }

            if (trim((string) ($part['description'] ?? '')) === '') {
                throw new InvalidArgumentException(__('Part #:number requires a description.', [
                    'number' => $partNumber,
                ]));
            }
        }

        $type = (string) $original->type;
        $transactedAt = $original->transacted_at;
        $reference = $original->reference;

        DB::transaction(function () use ($original, $account, $parts, $type, $reference, $transactedAt): void {
            $this->deleteTransaction($original);

            $account->refresh();

            foreach ($parts as $part) {
                $amount = round((float) $part['amount'], 2);
                $description = trim((string) $part['description']);

                if ($type === 'credit') {
                    $this->credit($account, $amount, $description, $reference, $transactedAt, $original->member_id);
                } else {
                    $this->debit($account, $amount, $description, $reference, $transactedAt, $original->member_id);
                }

                $account->refresh();
            }
        });
    }

    /**
     * Post an equal-and-opposite counter-entry on the same account, leaving the original intact.
     */
    public function createReversalEntry(
        Transaction $original,
        string $reason,
        ?DateTimeInterface $transactedAt = null,
    ): Transaction {
        $original->loadMissing('account');
        $account = $original->account;

        if ($account === null) {
            throw new InvalidArgumentException(__('Transaction has no account.'));
        }

        $trimmed = trim($reason);

        if ($trimmed === '') {
            throw new InvalidArgumentException(__('A reason is required for a reversal.'));
        }

        $counterType = $original->type === 'credit' ? 'debit' : 'credit';
        $amount = round((float) $original->amount, 2);

        if ($counterType === 'debit' && $account->type === 'cash' && $amount > (float) $account->balance) {
            throw new InvalidArgumentException(__('Reversal would exceed the available cash balance.'));
        }

        $description = __('Reversal of #:id: :original — :reason', [
            'id' => $original->id,
            'original' => $original->description ?? '—',
            'reason' => $trimmed,
        ]);

        if (! $account->is_master && $account->type === 'cash') {
            return $counterType === 'credit'
                ? $this->creditMemberCashWithMasterMirror(
                    $account,
                    $amount,
                    $description,
                    __('(reversal mirror)'),
                    $original,
                    $transactedAt,
                    $original->member_id,
                )
                : $this->debitMemberCashWithMasterMirror(
                    $account,
                    $amount,
                    $description,
                    __('(reversal mirror)'),
                    $original,
                    $transactedAt,
                    $original->member_id,
                );
        }

        if (! $account->is_master && $account->type === 'fund') {
            return $counterType === 'credit'
                ? $this->creditMemberFundWithMasterMirror(
                    $account,
                    $amount,
                    $description,
                    __('(reversal mirror)'),
                    $original,
                    $transactedAt,
                    $original->member_id,
                )
                : $this->debitMemberFundWithMasterMirror(
                    $account,
                    $amount,
                    $description,
                    __('(reversal mirror)'),
                    $original,
                    $transactedAt,
                    $original->member_id,
                );
        }

        $reversal = $counterType === 'credit'
            ? $this->credit($account, $amount, $description, $original, $transactedAt, $original->member_id)
            : $this->debit($account, $amount, $description, $original, $transactedAt, $original->member_id);

        return $reversal;
    }

    /**
     * Reverse every ledger line that shares the same polymorphic reference as the given entry.
     *
     * @return int Number of counter-entries created.
     */
    public function createFullSourceReversal(
        Transaction $transaction,
        string $reason,
        ?DateTimeInterface $transactedAt = null,
    ): int {
        if (blank($transaction->reference_type) || blank($transaction->reference_id)) {
            throw new InvalidArgumentException(__('This transaction has no source reference — use single-entry reversal instead.'));
        }

        if ($transaction->reference_type === Transaction::class) {
            throw new InvalidArgumentException(__('This transaction has no source reference — use single-entry reversal instead.'));
        }

        $siblings = Transaction::query()
            ->where('reference_type', $transaction->reference_type)
            ->where('reference_id', $transaction->reference_id)
            ->get();

        if ($siblings->isEmpty()) {
            throw new InvalidArgumentException(__('No ledger entries found for this source.'));
        }

        $count = 0;

        DB::transaction(function () use ($siblings, $reason, $transactedAt, &$count): void {
            foreach ($siblings as $entry) {
                if ($entry->account === null) {
                    continue;
                }

                $this->createReversalEntry($entry, $reason, $transactedAt);
                $count++;
            }
        });

        if ($count === 0) {
            throw new InvalidArgumentException(__('No ledger entries found for this source.'));
        }

        return $count;
    }

    public function canUseFullSourceReversal(Transaction $transaction): bool
    {
        return filled($transaction->reference_type)
            && filled($transaction->reference_id)
            && $transaction->reference_type !== Transaction::class;
    }

    public function countRelatedLedgerEntries(Transaction $transaction): int
    {
        if (blank($transaction->reference_type) || blank($transaction->reference_id)) {
            return 1;
        }

        return Transaction::query()
            ->where('reference_type', $transaction->reference_type)
            ->where('reference_id', $transaction->reference_id)
            ->count();
    }

    public function hasExistingReversal(Transaction $transaction): bool
    {
        return Transaction::query()
            ->where('reference_type', Transaction::class)
            ->where('reference_id', $transaction->id)
            ->exists();
    }

    public function isReversalEntry(Transaction $transaction): bool
    {
        return $transaction->reference_type === Transaction::class
            && filled($transaction->reference_id);
    }

    /**
     * Validates paired master journal legs (bank clearing, etc.) per §5.12.
     *
     * @return null|string Error message when debits and credits on the reference do not balance.
     */
    public function validateBalancedJournalForReference(Transaction $transaction): ?string
    {
        if (! $this->shouldValidateBalancedReference($transaction)) {
            return null;
        }

        if (blank($transaction->reference_type) || blank($transaction->reference_id)) {
            return null;
        }

        $siblings = Transaction::query()
            ->where('reference_type', $transaction->reference_type)
            ->where('reference_id', $transaction->reference_id)
            ->get();

        if ($siblings->count() < 2) {
            return null;
        }

        $debits = (float) $siblings->where('type', 'debit')->sum('amount');
        $credits = (float) $siblings->where('type', 'credit')->sum('amount');
        $tolerance = ContributionPolicySettings::reconTolerance();

        if (abs($debits - $credits) > $tolerance) {
            return __('Unbalanced journal for reference :type #:id (debits :debits, credits :credits).', [
                'type' => class_basename($transaction->reference_type),
                'id' => $transaction->reference_id,
                'debits' => number_format($debits, 2),
                'credits' => number_format($credits, 2),
            ]);
        }

        return null;
    }

    protected function shouldValidateBalancedReference(Transaction $transaction): bool
    {
        if ($transaction->reference_type === Transaction::class) {
            return false;
        }

        return in_array($transaction->reference_type, [
            BankTransaction::class,
            FundPosting::class,
        ], true);
    }

    /**
     * Debit a member cash account and master cash to record a refund paid to the member.
     */
    public function refundMemberCash(
        Account $memberCash,
        float $amount,
        string $description,
        ?DateTimeInterface $transactedAt = null,
    ): void {
        if ($memberCash->is_master || $memberCash->type !== 'cash') {
            throw new InvalidArgumentException(__('Refund can only be posted to a member cash account.'));
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException(__('Refund amount must be greater than zero.'));
        }

        if ($amount > (float) $memberCash->balance) {
            throw new InvalidArgumentException(__('Refund amount exceeds the available cash balance.'));
        }

        $reason = trim($description);

        if ($reason === '') {
            throw new InvalidArgumentException(__('Refund description is required.'));
        }

        $masterCash = Account::masterCash();

        if ($masterCash === null) {
            throw new InvalidArgumentException(__('Master cash account is not configured.'));
        }

        $memberCash->loadMissing('member');

        $refundDescription = __('Refund — :member — :reason', [
            'member' => $memberCash->member?->name ?? __('Member'),
            'reason' => $reason,
        ]);

        DB::transaction(function () use ($memberCash, $amount, $refundDescription, $transactedAt): void {
            $this->debitMemberCashWithMasterMirror(
                $memberCash,
                $amount,
                $refundDescription,
                __('(refund mirror)'),
                null,
                $transactedAt,
            );
        });
    }

    /**
     * Post a manual ledger credit using global pairing rules (member cash/fund, reserve accounts).
     */
    public function postManualCredit(
        Account $account,
        float $amount,
        string $description,
        ?DateTimeInterface $transactedAt = null,
        ?int $memberId = null,
    ): Transaction {
        return $this->postManualAdjustment($account, 'credit', $amount, $description, $transactedAt, $memberId);
    }

    /**
     * Post a manual ledger debit using global pairing rules (member cash/fund, reserve accounts).
     */
    public function postManualDebit(
        Account $account,
        float $amount,
        string $description,
        ?DateTimeInterface $transactedAt = null,
        ?int $memberId = null,
    ): Transaction {
        return $this->postManualAdjustment($account, 'debit', $amount, $description, $transactedAt, $memberId);
    }

    /**
     * @param  'credit'|'debit'  $direction
     */
    protected function postManualAdjustment(
        Account $account,
        string $direction,
        float $amount,
        string $description,
        ?DateTimeInterface $transactedAt = null,
        ?int $memberId = null,
    ): Transaction {
        if ($amount <= 0) {
            throw new InvalidArgumentException(__('Amount must be greater than zero.'));
        }

        $description = trim($description);

        if ($description === '') {
            throw new InvalidArgumentException(__('Description is required.'));
        }

        $memberId = $this->resolveTransactionMemberId($account, $memberId);
        $kind = $this->manualAdjustmentKind($account, $memberId);
        $memberForCollection = null;
        $primaryTransaction = null;

        if ($kind === 'member_cash') {
            $memberForCollection = Member::query()->find((int) $account->member_id);
        } elseif (in_array($kind, ['master_cash_tagged', 'master_bank_shadow'], true) && $memberId !== null) {
            $memberForCollection = Member::query()->find($memberId);
        }

        DB::transaction(function () use ($account, $direction, $amount, $description, $transactedAt, $memberId, $kind, &$memberForCollection, &$primaryTransaction): void {
            self::withoutMemberCashCollection(function () use ($account, $direction, $amount, $description, $transactedAt, $memberId, $kind, &$memberForCollection, &$primaryTransaction): void {
                $primaryTransaction = match ($kind) {
                    'member_cash' => $this->postManualMemberCashPair(
                        $account,
                        $direction,
                        $amount,
                        $description,
                        $transactedAt,
                        $memberId,
                    ),
                    'member_fund' => $this->postManualMemberFundPair(
                        $account,
                        $direction,
                        $amount,
                        $description,
                        $transactedAt,
                        $memberId,
                    ),
                    'master_cash_tagged' => $this->postManualMasterCashTaggedPair(
                        $direction,
                        $amount,
                        $description,
                        $transactedAt,
                        $memberId,
                    ),
                    'master_fund_tagged' => $this->postManualMasterFundTaggedPair(
                        $direction,
                        $amount,
                        $description,
                        $transactedAt,
                        $memberId,
                    ),
                    'reserve_vs_cash' => $this->postManualReserveVsCash(
                        $account,
                        $direction,
                        $amount,
                        $description,
                        $transactedAt,
                    ),
                    'reserve_vs_fund' => $this->postManualReserveVsFund(
                        $account,
                        $direction,
                        $amount,
                        $description,
                        $transactedAt,
                    ),
                    'master_bank_shadow' => $this->postManualMasterBankShadow(
                        $direction,
                        $amount,
                        $description,
                        $transactedAt,
                        $memberId,
                    ),
                    default => $direction === 'credit'
                    ? $this->credit($account, $amount, $description, null, $transactedAt, $memberId)
                    : $this->debit($account, $amount, $description, null, $transactedAt, $memberId),
                };
            });
        });

        if ($memberForCollection !== null) {
            $this->triggerMemberCashCollection($memberForCollection);
        }

        return $primaryTransaction;
    }

    /**
     * @return 'member_cash'|'member_fund'|'master_cash_tagged'|'master_fund_tagged'|'master_bank_shadow'|'reserve_vs_cash'|'reserve_vs_fund'|'single'
     */
    protected function manualAdjustmentKind(Account $account, ?int $memberId): string
    {
        if (! $account->is_master) {
            return match ($account->type) {
                'cash' => 'member_cash',
                'fund' => 'member_fund',
                default => 'single',
            };
        }

        return match ($account->type) {
            'bank' => 'master_bank_shadow',
            'cash' => $memberId !== null ? 'master_cash_tagged' : 'single',
            'fund' => $memberId !== null ? 'master_fund_tagged' : 'single',
            'expense', 'fees' => 'reserve_vs_cash',
            'invest' => 'reserve_vs_fund',
            default => 'single',
        };
    }

    /**
     * Manual master bank credit/debit mirrored to master cash; optional member tag mirrors to member cash.
     *
     * @param  'credit'|'debit'  $direction
     */
    protected function postManualMasterBankShadow(
        string $direction,
        float $amount,
        string $description,
        ?DateTimeInterface $transactedAt,
        ?int $memberId,
    ): Transaction {
        $masterBank = Account::masterBank();

        if ($masterBank === null) {
            throw new InvalidArgumentException(__('Master bank account is not configured.'));
        }

        $masterCash = Account::masterCash();

        if ($masterCash === null) {
            throw new InvalidArgumentException(__('Master cash account is not configured.'));
        }

        if ($memberId !== null) {
            $memberCash = $this->resolveMemberCashAccount($memberId);

            if ($direction === 'debit' && $amount > (float) $memberCash->balance) {
                throw new InvalidArgumentException(__('Debit amount exceeds the member cash balance.'));
            }

            self::withoutMasterPoolMirror(function () use ($direction, $memberCash, $amount, $description, $transactedAt, $memberId): void {
                if ($direction === 'debit') {
                    $this->debit($memberCash, $amount, $description, null, $transactedAt, $memberId);
                } else {
                    $this->credit($memberCash, $amount, $description, null, $transactedAt, $memberId);
                }
            });
        }

        if ($direction === 'debit' && $amount > (float) $masterCash->balance) {
            throw new InvalidArgumentException(__('Debit amount exceeds the master cash balance.'));
        }

        return $direction === 'credit'
            ? $this->pairManualCredit($masterBank, $masterCash, $amount, $description, $transactedAt, $memberId)
            : $this->pairManualDebit($masterBank, $masterCash, $amount, $description, $transactedAt, $memberId);
    }

    /**
     * @param  'credit'|'debit'  $direction
     */
    protected function postManualMemberCashPair(
        Account $memberCash,
        string $direction,
        float $amount,
        string $description,
        ?DateTimeInterface $transactedAt,
        ?int $memberId,
    ): Transaction {
        if ($memberCash->is_master || $memberCash->type !== 'cash') {
            throw new InvalidArgumentException(__('Manual adjustment must target a member cash account.'));
        }

        $masterCash = Account::masterCash();

        if ($masterCash === null) {
            throw new InvalidArgumentException(__('Master cash account is not configured.'));
        }

        if ($direction === 'debit' && $amount > (float) $memberCash->balance) {
            throw new InvalidArgumentException(__('Debit amount exceeds the available cash balance.'));
        }

        return $direction === 'credit'
            ? $this->pairManualCredit($memberCash, $masterCash, $amount, $description, $transactedAt, $memberId)
            : $this->pairManualDebit($memberCash, $masterCash, $amount, $description, $transactedAt, $memberId);
    }

    /**
     * @param  'credit'|'debit'  $direction
     */
    protected function postManualMemberFundPair(
        Account $memberFund,
        string $direction,
        float $amount,
        string $description,
        ?DateTimeInterface $transactedAt,
        ?int $memberId,
    ): Transaction {
        if ($memberFund->is_master || $memberFund->type !== 'fund') {
            throw new InvalidArgumentException(__('Manual adjustment must target a member fund account.'));
        }

        $masterFund = Account::masterFund();

        if ($masterFund === null) {
            throw new InvalidArgumentException(__('Master fund account is not configured.'));
        }

        return $direction === 'credit'
            ? $this->pairManualCredit($memberFund, $masterFund, $amount, $description, $transactedAt, $memberId)
            : $this->pairManualDebit($memberFund, $masterFund, $amount, $description, $transactedAt, $memberId);
    }

    /**
     * @param  'credit'|'debit'  $direction
     */
    protected function postManualMasterCashTaggedPair(
        string $direction,
        float $amount,
        string $description,
        ?DateTimeInterface $transactedAt,
        ?int $memberId,
    ): Transaction {
        $masterCash = Account::masterCash();

        if ($masterCash === null) {
            throw new InvalidArgumentException(__('Master cash account is not configured.'));
        }

        $memberCash = $this->resolveMemberCashAccount($memberId);

        if ($direction === 'debit' && $amount > (float) $memberCash->balance) {
            throw new InvalidArgumentException(__('Debit amount exceeds the member cash balance.'));
        }

        return $direction === 'credit'
            ? $this->pairManualCredit($masterCash, $memberCash, $amount, $description, $transactedAt, $memberId)
            : $this->pairManualDebit($masterCash, $memberCash, $amount, $description, $transactedAt, $memberId);
    }

    /**
     * @param  'credit'|'debit'  $direction
     */
    protected function postManualMasterFundTaggedPair(
        string $direction,
        float $amount,
        string $description,
        ?DateTimeInterface $transactedAt,
        ?int $memberId,
    ): Transaction {
        $masterFund = Account::masterFund();

        if ($masterFund === null) {
            throw new InvalidArgumentException(__('Master fund account is not configured.'));
        }

        $memberFund = $this->resolveMemberFundAccount($memberId);

        return $direction === 'credit'
            ? $this->pairManualCredit($masterFund, $memberFund, $amount, $description, $transactedAt, $memberId)
            : $this->pairManualDebit($masterFund, $memberFund, $amount, $description, $transactedAt, $memberId);
    }

    /**
     * Credit/debit expense or fees against master cash.
     *
     * @param  'credit'|'debit'  $direction
     */
    protected function postManualReserveVsCash(
        Account $reserveAccount,
        string $direction,
        float $amount,
        string $description,
        ?DateTimeInterface $transactedAt,
    ): Transaction {
        $this->assertMasterReserveCashCounterpart($reserveAccount);

        if ($direction === 'debit' && $amount > (float) $reserveAccount->balance) {
            throw new InvalidArgumentException(__('Debit amount exceeds the available reserve balance.'));
        }

        $masterCash = Account::masterCash();

        if ($masterCash === null) {
            throw new InvalidArgumentException(__('Master cash account is not configured.'));
        }

        if ($direction === 'credit') {
            $this->debit($masterCash, $amount, $description, null, $transactedAt);

            return $this->credit($reserveAccount, $amount, $description, null, $transactedAt);
        }

        $this->credit($masterCash, $amount, $description, null, $transactedAt);

        return $this->debit($reserveAccount, $amount, $description, null, $transactedAt);
    }

    /**
     * Credit/debit invest against master fund.
     *
     * @param  'credit'|'debit'  $direction
     */
    protected function postManualReserveVsFund(
        Account $investAccount,
        string $direction,
        float $amount,
        string $description,
        ?DateTimeInterface $transactedAt,
    ): Transaction {
        $this->assertMasterInvestAccount($investAccount);

        if ($direction === 'debit' && $amount > (float) $investAccount->balance) {
            throw new InvalidArgumentException(__('Debit amount exceeds the available invest balance.'));
        }

        $masterFund = Account::masterFund();

        if ($masterFund === null) {
            throw new InvalidArgumentException(__('Master fund account is not configured.'));
        }

        if ($direction === 'credit') {
            $this->debit($masterFund, $amount, $description, null, $transactedAt);

            return $this->credit($investAccount, $amount, $description, null, $transactedAt);
        }

        $this->credit($masterFund, $amount, $description, null, $transactedAt);

        return $this->debit($investAccount, $amount, $description, null, $transactedAt);
    }

    protected function pairManualCredit(
        Account $primary,
        Account $counterpart,
        float $amount,
        string $description,
        ?DateTimeInterface $transactedAt,
        ?int $memberId,
    ): Transaction {
        return self::withoutMasterPoolMirror(function () use ($primary, $counterpart, $amount, $description, $transactedAt, $memberId): Transaction {
            $this->credit($counterpart, $amount, $description, null, $transactedAt, $memberId);

            return $this->credit($primary, $amount, $description, null, $transactedAt, $memberId);
        });
    }

    protected function pairManualDebit(
        Account $primary,
        Account $counterpart,
        float $amount,
        string $description,
        ?DateTimeInterface $transactedAt,
        ?int $memberId,
    ): Transaction {
        return self::withoutMasterPoolMirror(function () use ($primary, $counterpart, $amount, $description, $transactedAt, $memberId): Transaction {
            $this->debit($counterpart, $amount, $description, null, $transactedAt, $memberId);

            return $this->debit($primary, $amount, $description, null, $transactedAt, $memberId);
        });
    }

    protected function resolveMemberCashAccount(?int $memberId): Account
    {
        if ($memberId === null) {
            throw new InvalidArgumentException(__('A member must be selected for this adjustment.'));
        }

        $member = Member::query()->with('cashAccount')->find($memberId);

        if ($member?->cashAccount === null) {
            throw new InvalidArgumentException(__('Member cash account is not configured.'));
        }

        return $member->cashAccount;
    }

    protected function resolveMemberFundAccount(?int $memberId): Account
    {
        if ($memberId === null) {
            throw new InvalidArgumentException(__('A member must be selected for this adjustment.'));
        }

        $member = Member::query()->with('fundAccount')->find($memberId);

        if ($member?->fundAccount === null) {
            throw new InvalidArgumentException(__('Member fund account is not configured.'));
        }

        return $member->fundAccount;
    }

    protected function assertMasterReserveCashCounterpart(Account $account): void
    {
        if (! $account->is_master || ! in_array($account->type, ['expense', 'fees'], true)) {
            throw new InvalidArgumentException(__('Reserve account must be a master expense or fees account.'));
        }
    }

    protected function assertMasterInvestAccount(Account $account): void
    {
        if (! $account->is_master || $account->type !== 'invest') {
            throw new InvalidArgumentException(__('Account must be the master invest account.'));
        }
    }

    /**
     * Fund a master reserve account (expense or investment) from master fund.
     */
    public function fundReserveAccountFromMasterFund(
        Account $reserveAccount,
        float $amount,
        string $description,
        ?DateTimeInterface $transactedAt = null,
    ): void {
        $this->assertMasterReserveAccount($reserveAccount);

        if ($amount <= 0) {
            throw new InvalidArgumentException(__('Amount must be greater than zero.'));
        }

        $masterFund = Account::masterFund();

        if ($masterFund === null) {
            throw new InvalidArgumentException(__('Master fund account is not configured.'));
        }

        if ($amount > (float) $masterFund->balance) {
            throw new InvalidArgumentException(__('Amount exceeds the available master fund balance.'));
        }

        $description = trim($description);

        if ($description === '') {
            throw new InvalidArgumentException(__('Description is required.'));
        }

        $fundTransferDescription = __(':description (master fund transfer)', ['description' => $description]);
        $reserveFundingDescription = __(':description (reserve funding)', ['description' => $description]);

        DB::transaction(function () use ($masterFund, $reserveAccount, $amount, $fundTransferDescription, $reserveFundingDescription, $transactedAt): void {
            $this->debit($masterFund, $amount, $fundTransferDescription, null, $transactedAt);
            $this->credit($reserveAccount, $amount, $reserveFundingDescription, null, $transactedAt);
        });
    }

    /**
     * Disburse from a master reserve account via master cash (check outflow).
     */
    public function disburseReserveAccountByCheck(
        Account $reserveAccount,
        float $amount,
        string $description,
        ?DateTimeInterface $transactedAt = null,
    ): void {
        $this->assertMasterReserveAccount($reserveAccount);

        if ($amount <= 0) {
            throw new InvalidArgumentException(__('Amount must be greater than zero.'));
        }

        if ($amount > (float) $reserveAccount->balance) {
            throw new InvalidArgumentException(__('Amount exceeds the available reserve balance.'));
        }

        $masterCash = Account::masterCash();

        if ($masterCash === null) {
            throw new InvalidArgumentException(__('Master cash account is not configured.'));
        }

        $description = trim($description);

        if ($description === '') {
            throw new InvalidArgumentException(__('Description is required.'));
        }

        $toCashDescription = __(':description (to master cash)', ['description' => $description]);
        $fromReserveDescription = __(':description (from reserve)', ['description' => $description]);
        $checkOutDescription = __(':description (check out)', ['description' => $description]);

        DB::transaction(function () use ($reserveAccount, $masterCash, $amount, $toCashDescription, $fromReserveDescription, $checkOutDescription, $transactedAt): void {
            $this->debit($reserveAccount, $amount, $toCashDescription, null, $transactedAt);
            $this->credit($masterCash, $amount, $fromReserveDescription, null, $transactedAt);
            $this->debit($masterCash, $amount, $checkOutDescription, null, $transactedAt);
        });
    }

    /**
     * Record investment return received through master cash into master invest.
     */
    public function recordInvestmentReturn(
        float $amount,
        string $description,
        ?DateTimeInterface $transactedAt = null,
    ): void {
        if ($amount <= 0) {
            throw new InvalidArgumentException(__('Amount must be greater than zero.'));
        }

        $masterInvest = Account::masterInvest();

        if ($masterInvest === null) {
            throw new InvalidArgumentException(__('Master invest account is not configured.'));
        }

        $masterCash = Account::masterCash();

        if ($masterCash === null) {
            throw new InvalidArgumentException(__('Master cash account is not configured.'));
        }

        $description = trim($description);

        if ($description === '') {
            throw new InvalidArgumentException(__('Description is required.'));
        }

        $receiptDescription = __(':description (receipt)', ['description' => $description]);
        $transferDescription = __(':description (transfer to invest account)', ['description' => $description]);
        $returnDescription = __(':description (investment return)', ['description' => $description]);

        DB::transaction(function () use ($masterInvest, $masterCash, $amount, $receiptDescription, $transferDescription, $returnDescription, $transactedAt): void {
            $this->credit($masterCash, $amount, $receiptDescription, null, $transactedAt);
            $this->debit($masterCash, $amount, $transferDescription, null, $transactedAt);
            $this->credit($masterInvest, $amount, $returnDescription, null, $transactedAt);
        });
    }

    /**
     * Mirror a transaction to an account (credit or debit based on amount sign)
     * without affecting the source account. Used when posting bank transactions
     * to master cash, or mirroring to master fund.
     */
    public function mirror(
        Account $account,
        float $amount,
        string $description,
        ?Model $reference = null,
        ?DateTimeInterface $transactedAt = null,
    ): Transaction {
        if ($amount >= 0) {
            return $this->credit($account, $amount, $description, $reference, $transactedAt);
        }

        return $this->debit($account, abs($amount), $description, $reference, $transactedAt);
    }

    /**
     * Debit member cash and mirror the same amount on master cash (pool outflow).
     */
    public function debitMemberCashWithMasterMirror(
        Account $memberCash,
        float $amount,
        string $description,
        string $mirrorSuffix,
        ?Model $reference = null,
        ?DateTimeInterface $transactedAt = null,
        ?int $memberId = null,
    ): Transaction {
        if ($amount <= 0.00001) {
            throw new InvalidArgumentException(__('Amount must be greater than zero.'));
        }

        $masterCash = Account::masterCash();

        if ($masterCash === null) {
            throw new InvalidArgumentException(__('Master cash account is not configured.'));
        }

        $resolvedMemberId = $this->resolveTransactionMemberId($memberCash, $memberId);

        self::$masterPoolMirrorDepth++;

        $masterReference = $this->masterPoolMirrorReference($reference);

        try {
            $memberTransaction = $this->debit($memberCash, $amount, $description, $reference, $transactedAt, $resolvedMemberId);
            $this->debit(
                $masterCash,
                $amount,
                $this->masterPoolMirrorLegDescription($description, $resolvedMemberId, $mirrorSuffix),
                $masterReference,
                $transactedAt,
                $resolvedMemberId,
            );

            return $memberTransaction;
        } finally {
            self::$masterPoolMirrorDepth--;
        }
    }

    /**
     * Credit member cash and mirror the same amount on master cash (pool inflow).
     */
    public function creditMemberCashWithMasterMirror(
        Account $memberCash,
        float $amount,
        string $description,
        string $mirrorSuffix,
        ?Model $reference = null,
        ?DateTimeInterface $transactedAt = null,
        ?int $memberId = null,
    ): Transaction {
        if ($amount <= 0.00001) {
            throw new InvalidArgumentException(__('Amount must be greater than zero.'));
        }

        $masterCash = Account::masterCash();

        if ($masterCash === null) {
            throw new InvalidArgumentException(__('Master cash account is not configured.'));
        }

        $resolvedMemberId = $this->resolveTransactionMemberId($memberCash, $memberId);

        self::$masterPoolMirrorDepth++;

        $masterReference = $this->masterPoolMirrorReference($reference);

        try {
            $this->credit(
                $masterCash,
                $amount,
                $this->masterPoolMirrorLegDescription($description, $resolvedMemberId, $mirrorSuffix),
                $masterReference,
                $transactedAt,
                $resolvedMemberId,
            );

            return $this->credit($memberCash, $amount, $description, $reference, $transactedAt, $resolvedMemberId);
        } finally {
            self::$masterPoolMirrorDepth--;
            $this->dispatchMemberCashIncreasedAfterPoolMirror($memberCash, $resolvedMemberId);
        }
    }

    /**
     * Debit member fund and mirror the same amount on master fund (pool outflow).
     */
    public function debitMemberFundWithMasterMirror(
        Account $memberFund,
        float $amount,
        string $description,
        string $mirrorSuffix,
        ?Model $reference = null,
        ?DateTimeInterface $transactedAt = null,
        ?int $memberId = null,
    ): Transaction {
        if ($amount <= 0.00001) {
            throw new InvalidArgumentException(__('Amount must be greater than zero.'));
        }

        $masterFund = Account::masterFund();

        if ($masterFund === null) {
            throw new InvalidArgumentException(__('Master fund account is not configured.'));
        }

        $resolvedMemberId = $this->resolveTransactionMemberId($memberFund, $memberId);

        self::$masterPoolMirrorDepth++;

        $masterReference = $this->masterPoolMirrorReference($reference);

        try {
            $memberTransaction = $this->debit($memberFund, $amount, $description, $reference, $transactedAt, $resolvedMemberId);
            $this->debit(
                $masterFund,
                $amount,
                $this->masterPoolMirrorLegDescription($description, $resolvedMemberId, $mirrorSuffix),
                $masterReference,
                $transactedAt,
                $resolvedMemberId,
            );

            return $memberTransaction;
        } finally {
            self::$masterPoolMirrorDepth--;
        }
    }

    /**
     * Credit member fund and mirror the same amount on master fund (pool inflow).
     */
    public function creditMemberFundWithMasterMirror(
        Account $memberFund,
        float $amount,
        string $description,
        string $mirrorSuffix,
        ?Model $reference = null,
        ?DateTimeInterface $transactedAt = null,
        ?int $memberId = null,
    ): Transaction {
        if ($amount <= 0.00001) {
            throw new InvalidArgumentException(__('Amount must be greater than zero.'));
        }

        $masterFund = Account::masterFund();

        if ($masterFund === null) {
            throw new InvalidArgumentException(__('Master fund account is not configured.'));
        }

        $resolvedMemberId = $this->resolveTransactionMemberId($memberFund, $memberId);

        self::$masterPoolMirrorDepth++;

        $masterReference = $this->masterPoolMirrorReference($reference);

        try {
            $this->credit(
                $masterFund,
                $amount,
                $this->masterPoolMirrorLegDescription($description, $resolvedMemberId, $mirrorSuffix),
                $masterReference,
                $transactedAt,
                $resolvedMemberId,
            );

            return $this->credit($memberFund, $amount, $description, $reference, $transactedAt, $resolvedMemberId);
        } finally {
            self::$masterPoolMirrorDepth--;
        }
    }

    /**
     * Fund deposit accept tags only the member leg with {@see FundPosting}; the pool mirror must not
     * share that reference or paired-journal validation sees two credits and no debits.
     */
    private function masterPoolMirrorReference(?Model $memberLegReference): ?Model
    {
        if ($memberLegReference instanceof FundPosting) {
            return null;
        }

        return $memberLegReference;
    }

    private function masterPoolMirrorLegDescription(string $description, ?int $memberId, string $mirrorSuffix): string
    {
        $base = trim($description);

        if ($memberId === null) {
            return $base === '' ? trim($mirrorSuffix) : $base.' '.trim($mirrorSuffix);
        }

        $memberName = Member::query()->whereKey($memberId)->value('name');

        if (filled($memberName)) {
            return __(':description (:member)', [
                'description' => $base,
                'member' => $memberName,
            ]);
        }

        return $base === '' ? trim($mirrorSuffix) : $base.' '.trim($mirrorSuffix);
    }

    private function assertMasterReserveAccount(Account $account): void
    {
        if (! $account->is_master || ! in_array($account->type, ['expense', 'fees', 'invest'], true)) {
            throw new InvalidArgumentException(__('Reserve account must be a master expense, fees, or investment account.'));
        }
    }

    /**
     * Run contribution and loan settlement after member cash increases outside ledger posting.
     */
    public function triggerMemberCashCollection(Member $member): void
    {
        $member = $member->fresh() ?? $member;
        $member->unsetRelation('accounts');

        if (self::$memberCashCollectionDepth > 0) {
            return;
        }

        self::$memberCashCollectionDepth++;
        self::$memberCashSettlementActive = true;

        try {
            app(ContributionCollectionCycleService::class)->onMemberCashIncreased($member);
        } finally {
            self::$memberCashSettlementActive = false;
            self::$memberCashCollectionDepth--;
        }
    }

    /**
     * Pool-mirror credits suppress per-leg dispatch while {@see $masterPoolMirrorDepth} > 0;
     * run settlement once after the member cash leg is posted (deposit accept, etc.).
     */
    private function dispatchMemberCashIncreasedAfterPoolMirror(Account $memberCash, ?int $resolvedMemberId): void
    {
        if (self::$masterPoolMirrorDepth !== 0) {
            return;
        }

        $memberId = $memberCash->member_id ?? $resolvedMemberId;

        if ($memberId === null) {
            return;
        }

        $member = Member::query()->find($memberId);

        if ($member === null) {
            return;
        }

        $this->triggerMemberCashCollection($member);
    }

    private function dispatchMemberCashIncreasedIfApplicable(Account $account, ?int $resolvedMemberId = null): void
    {
        if (self::masterPoolMirrorInProgress()) {
            return;
        }

        $memberId = $account->member_id ?? $resolvedMemberId;

        if ($memberId === null) {
            return;
        }

        if ($account->is_master) {
            if ($account->type !== 'cash') {
                return;
            }

            $member = Member::query()->find($memberId);

            if ($member === null) {
                return;
            }

            if (self::$memberCashCollectionDepth > 0) {
                return;
            }

            self::$memberCashCollectionDepth++;
            self::$memberCashSettlementActive = true;

            try {
                app(ContributionCollectionCycleService::class)->onMemberCashIncreased($member);
            } finally {
                self::$memberCashSettlementActive = false;
                self::$memberCashCollectionDepth--;
            }

            return;
        }

        if ($account->type !== 'cash') {
            return;
        }

        $member = Member::query()->find($memberId);

        if ($member === null) {
            return;
        }

        if (self::$memberCashCollectionDepth > 0) {
            return;
        }

        self::$memberCashCollectionDepth++;
        self::$memberCashSettlementActive = true;

        try {
            app(ContributionCollectionCycleService::class)->onMemberCashIncreased($member);
        } finally {
            self::$memberCashSettlementActive = false;
            self::$memberCashCollectionDepth--;
        }
    }

    private function guardMemberCashDebitDuringAutoCollection(Account $account, float $amount): void
    {
        if (! self::$memberCashSettlementActive || $account->is_master || $account->type !== 'cash') {
            return;
        }

        if ($amount > (float) $account->balance + 0.00001) {
            throw new InsufficientMemberCashForCollectionException(
                __('Insufficient member cash balance for auto-collection.'),
            );
        }
    }

    private function resolveTransactionMemberId(Account $account, ?int $memberId): ?int
    {
        if ($account->member_id !== null) {
            return (int) $account->member_id;
        }

        if ($memberId === null) {
            return null;
        }

        if (! Member::query()->whereKey($memberId)->exists()) {
            throw new InvalidArgumentException(__('Selected member does not exist.'));
        }

        return $memberId;
    }

    /**
     * Create the two member accounts (cash + fund) when a member is created.
     */
    public function createMemberAccounts(Member $member): void
    {
        Account::query()->firstOrCreate(
            [
                'member_id' => $member->id,
                'type' => 'cash',
                'is_master' => false,
            ],
            [
                'name' => $member->name.' - Cash',
                'balance' => 0,
            ],
        );

        Account::query()->firstOrCreate(
            [
                'member_id' => $member->id,
                'type' => 'fund',
                'is_master' => false,
            ],
            [
                'name' => $member->name.' - Fund',
                'balance' => 0,
            ],
        );
    }

    public function postContribution(Contribution $contribution): void
    {
        $amount = (float) $contribution->amount;
        $lateFee = (float) ($contribution->late_fee_amount ?? 0);

        DB::transaction(function () use ($contribution, $amount, $lateFee): void {
            $this->postContributionPrincipal($contribution, $amount);

            if ($lateFee > 0.00001) {
                $this->postContributionLateFee($contribution, $lateFee);
            }
        });
    }

    public function postContributionPrincipal(Contribution $contribution, float $amount): void
    {
        if ($amount <= 0.00001) {
            return;
        }

        $contribution->loadMissing('member');
        $member = $contribution->member;
        $memberCash = $member->cashAccount;
        $memberFund = $member->fundAccount;
        $masterFund = Account::masterFund();

        if ($memberCash === null || $memberFund === null || $masterFund === null) {
            throw new InvalidArgumentException(__('Member accounts are not configured.'));
        }

        $periodLabel = $contribution->period?->format('M Y') ?? '';
        $description = __('Contribution — :period', ['period' => $periodLabel]);

        if ($contribution->payment_method === Contribution::PAYMENT_METHOD_CASH_ACCOUNT) {
            $this->debitMemberCashWithMasterMirror(
                $memberCash,
                $amount,
                $description,
                __('(contribution mirror)'),
                $contribution,
            );
        }

        $this->creditMemberFundWithMasterMirror(
            $memberFund,
            $amount,
            $description,
            __('(contribution mirror)'),
            $contribution,
        );
    }

    public function postContributionLateFee(Contribution $contribution, float $lateFee): void
    {
        if ($lateFee <= 0.00001) {
            return;
        }

        $contribution->loadMissing('member');
        $memberCash = $contribution->member->cashAccount;
        $masterFees = Account::masterFees();

        if ($memberCash === null) {
            throw new InvalidArgumentException(__('Member cash account is not configured.'));
        }

        if ($masterFees === null) {
            throw new InvalidArgumentException(__('Master fees account is not configured.'));
        }

        $description = __('Contribution late fee — :period', [
            'period' => $contribution->period?->format('M Y') ?? '',
        ]);

        $this->debitMemberCashWithMasterMirror(
            $memberCash,
            $lateFee,
            $description,
            __('(contribution late fee mirror)'),
            $contribution,
        );
        $this->credit($masterFees, $lateFee, $description, $contribution);
    }

    /**
     * Member-cash late fee debits for this contribution (excludes master cash mirror legs).
     *
     * @return Builder<Transaction>
     */
    public function contributionLateFeeMemberCashDebitQuery(Contribution $contribution): Builder
    {
        $contribution->loadMissing('member.cashAccount');

        $cashAccountId = $contribution->member?->cashAccount?->id;
        $descriptionPrefix = __('Contribution late fee —');

        $query = Transaction::query()
            ->where('reference_type', Contribution::class)
            ->where('reference_id', $contribution->id)
            ->where('type', 'debit')
            ->where('description', 'like', $descriptionPrefix.'%');

        if ($cashAccountId === null) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where('account_id', $cashAccountId);
    }

    /**
     * Cash already debited for this contribution's late fee (tier accrual or prior partial collection).
     */
    public function contributionLateFeeCollectedAmount(Contribution $contribution): float
    {
        return (float) $this->contributionLateFeeMemberCashDebitQuery($contribution)->sum('amount');
    }

    public function contributionLateFeeMemberCashDebitCount(Contribution $contribution): int
    {
        return (int) $this->contributionLateFeeMemberCashDebitQuery($contribution)->count();
    }

    public function reverseContributionLateFee(Contribution $contribution, float $lateFee): void
    {
        if ($lateFee <= 0.00001) {
            return;
        }

        $contribution->loadMissing('member');
        $memberCash = $contribution->member->cashAccount;
        $masterFees = Account::masterFees();

        if ($memberCash === null || $masterFees === null) {
            throw new InvalidArgumentException(__('Accounts are not configured for late fee reversal.'));
        }

        $description = __('Late fee reversal — :period', [
            'period' => $contribution->period?->format('M Y') ?? '',
        ]);

        $this->creditMemberCashWithMasterMirror(
            $memberCash,
            $lateFee,
            $description,
            __('(contribution late fee mirror)'),
            $contribution,
        );
        $this->debit($masterFees, $lateFee, $description, $contribution);
    }

    public function postInstallmentLateFee(LoanInstallment $installment, float $lateFee): void
    {
        if ($lateFee <= 0.00001) {
            return;
        }

        $installment->loadMissing('loan.member');
        $member = $installment->loan->member;
        $memberCash = $member->cashAccount;
        $masterFees = Account::masterFees();

        if ($memberCash === null || $masterFees === null) {
            throw new InvalidArgumentException(__('Accounts are not configured for installment late fee.'));
        }

        $description = __('EMI late fee — loan #:id inst. :num', [
            'id' => $installment->loan_id,
            'num' => $installment->installment_number,
        ]);

        $this->debitMemberCashWithMasterMirror(
            $memberCash,
            $lateFee,
            $description,
            __('(EMI late fee mirror)'),
            $installment,
            now(),
            $member->id,
        );
        $this->credit($masterFees, $lateFee, $description, $installment, now(), $member->id);
    }

    public function reverseInstallmentLateFee(LoanInstallment $installment, float $lateFee): void
    {
        if ($lateFee <= 0.00001) {
            return;
        }

        $installment->loadMissing('loan.member');
        $memberCash = $installment->loan->member->cashAccount;
        $masterFees = Account::masterFees();

        if ($memberCash === null || $masterFees === null) {
            throw new InvalidArgumentException(__('Accounts are not configured for installment late fee reversal.'));
        }

        $description = __('EMI late fee reversal — loan #:id inst. :num', [
            'id' => $installment->loan_id,
            'num' => $installment->installment_number,
        ]);

        $installment->loadMissing('loan.member');
        $memberId = $installment->loan->member_id;

        $this->creditMemberCashWithMasterMirror(
            $memberCash,
            $lateFee,
            $description,
            __('(EMI late fee mirror)'),
            $installment,
            null,
            $memberId,
        );
        $this->debit($masterFees, $lateFee, $description, $installment);
    }

    public function reverseContributionPrincipal(Contribution $contribution, float $amount): void
    {
        if ($amount <= 0.00001) {
            return;
        }

        $contribution->loadMissing('member');
        $member = $contribution->member;
        $memberCash = $member->cashAccount;
        $memberFund = $member->fundAccount;
        $masterFund = Account::masterFund();

        if ($memberCash === null || $memberFund === null || $masterFund === null) {
            throw new InvalidArgumentException(__('Member accounts are not configured.'));
        }

        $periodLabel = $contribution->period?->format('M Y') ?? '';
        $description = __('Contribution reversal — :period', ['period' => $periodLabel]);

        if ($contribution->payment_method === Contribution::PAYMENT_METHOD_CASH_ACCOUNT) {
            $this->creditMemberCashWithMasterMirror(
                $memberCash,
                $amount,
                $description,
                __('(contribution reversal mirror)'),
                $contribution,
            );
        }

        $this->debitMemberFundWithMasterMirror(
            $memberFund,
            $amount,
            $description,
            __('(contribution reversal mirror)'),
            $contribution,
        );
    }

    public function fundDependentCashAccount(
        Member $parent,
        Member $dependent,
        float $amount,
        string $note = '',
        bool $triggerCollection = false,
    ): void {
        if ($amount <= 0) {
            throw new InvalidArgumentException(__('Amount must be greater than zero.'));
        }

        $parentCash = $parent->cashAccount;
        $dependentCash = $dependent->cashAccount;

        if ($parentCash === null || $dependentCash === null) {
            throw new InvalidArgumentException(__('Member cash accounts are not configured.'));
        }

        if ((float) $parentCash->balance < $amount) {
            throw new RuntimeException(__('Insufficient parent cash balance.'));
        }

        $debitDesc = trim(__('Transfer to :name', ['name' => $dependent->name]).($note ? " — {$note}" : ''));
        $creditDesc = trim(__('Transfer from :name', ['name' => $parent->name]).($note ? " — {$note}" : ''));

        DB::transaction(function () use ($parentCash, $dependentCash, $amount, $debitDesc, $creditDesc): void {
            self::withoutMemberCashCollection(function () use ($parentCash, $dependentCash, $amount, $debitDesc, $creditDesc): void {
                $this->debitMemberCashWithMasterMirror(
                    $parentCash,
                    $amount,
                    $debitDesc,
                    __('(dependent transfer mirror)'),
                );
                $this->creditMemberCashWithMasterMirror(
                    $dependentCash,
                    $amount,
                    $creditDesc,
                    __('(dependent transfer mirror)'),
                );
            });
        });

        if ($triggerCollection) {
            $this->triggerMemberCashCollection($dependent->fresh() ?? $dependent);
        }
    }
}
