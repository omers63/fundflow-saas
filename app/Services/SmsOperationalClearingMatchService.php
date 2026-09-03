<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\SmsOpsClearanceMatchGroup;
use App\Models\Tenant\SmsTransaction;
use App\Models\Tenant\Transaction;
use App\Notifications\Tenant\CashOutBankClearedNotification;
use App\Notifications\Tenant\FundPostingBankClearedNotification;
use App\Support\BusinessDay;
use App\Support\ContributionPolicySettings;
use App\Support\EvidenceChannelSettings;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Operational bank rows ↔ SMS evidence matching (SMS-only / dual-channel ops clearance).
 *
 * Clears synthetic operational rows using posted SMS alerts as evidence.
 * Posts master bank on clearance when applicable; never re-posts member cash.
 */
class SmsOperationalClearingMatchService
{
    public function __construct(
        private BankClearingMatchService $bankClearing,
        private BankTransactionClearanceService $clearance,
        private AccountingService $accounting,
        private FundAuditLogService $audit,
    ) {}

    public function isEnabled(): bool
    {
        return EvidenceChannelSettings::usesSms();
    }

    public function linkMatchGroupCount(): int
    {
        return SmsOpsClearanceMatchGroup::query()->count();
    }

    public function isSmsOpsMatchEligible(SmsTransaction $sms): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        if ($sms->is_duplicate || $sms->is_ops_cleared || ! $sms->isPosted()) {
            return false;
        }

        if ($sms->sms_ops_clearance_match_group_id !== null) {
            return false;
        }

        return $sms->amount !== null && abs((float) $sms->amount) > 0.00001;
    }

    public function isOpsMatchEligible(BankTransaction $ops): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        if ($ops->sms_ops_clearance_match_group_id !== null) {
            return false;
        }

        if (! $this->bankClearing->isSyntheticOperationalStatement($ops)) {
            return false;
        }

        return $this->bankClearing->isPendingClearance($ops);
    }

    public function clearMatchPair(BankTransaction $ops, SmsTransaction $sms): void
    {
        $this->assertOpsEligible($ops);
        $this->assertSmsEligible($sms);

        if (! $this->pairAmountsMatch($ops, $sms)) {
            throw new InvalidArgumentException(__('Operational row and SMS amounts do not balance within tolerance.'));
        }

        DB::transaction(function () use ($ops, $sms): void {
            $clearedAt = BusinessDay::now();
            $group = SmsOpsClearanceMatchGroup::query()->create(['cleared_at' => $clearedAt]);

            $this->clearOperationalRow($ops);
            $this->linkOpsToGroup($ops, $group->id);
            $this->linkSmsToGroup($sms, $group->id, $clearedAt);
            $this->postMasterBankLedgerForSmsOpsMatch($sms->fresh(), $ops->fresh());

            $this->audit->log('SMS_OPS_MATCH_LINKED', 'sms_clearing', $group, $sms->member, [
                'operational_bank_transaction_id' => $ops->id,
                'sms_transaction_id' => $sms->id,
                'sms_ops_clearance_match_group_id' => $group->id,
            ]);
        });
    }

    /**
     * @param  Collection<int, BankTransaction>  $opsRows
     * @param  Collection<int, SmsTransaction>  $smsRows
     */
    public function clearMatchGroup(Collection $opsRows, Collection $smsRows): void
    {
        $opsRows = $opsRows->values();
        $smsRows = $smsRows->values();

        if ($opsRows->isEmpty() || $smsRows->isEmpty()) {
            throw new InvalidArgumentException(__('Select at least one operational row and one SMS row.'));
        }

        $shape = match (true) {
            $opsRows->count() === 1 && $smsRows->count() === 1 => null,
            $opsRows->count() === 1 && $smsRows->count() >= 2 => 'one_to_many',
            $smsRows->count() === 1 && $opsRows->count() >= 2 => 'many_to_one',
            default => throw new InvalidArgumentException(__('Group match requires 1→N or N→1 in Phase 7 v1.')),
        };

        foreach ($opsRows as $row) {
            if (! $row instanceof BankTransaction) {
                throw new InvalidArgumentException(__('One or more operational rows are not eligible for SMS matching.'));
            }

            $this->assertOpsEligible($row);
        }

        foreach ($smsRows as $row) {
            if (! $row instanceof SmsTransaction) {
                throw new InvalidArgumentException(__('One or more SMS rows are not eligible for operational matching.'));
            }

            $this->assertSmsEligible($row);
        }

        if (! $this->groupAmountsMatch($opsRows, $smsRows)) {
            throw new InvalidArgumentException(__('Selected amounts do not balance within tolerance.'));
        }

        DB::transaction(function () use ($opsRows, $smsRows, $shape): void {
            $clearedAt = BusinessDay::now();
            $group = SmsOpsClearanceMatchGroup::query()->create(['cleared_at' => $clearedAt]);

            foreach ($opsRows as $ops) {
                $this->clearOperationalRow($ops);
                $this->linkOpsToGroup($ops, $group->id);
            }

            foreach ($smsRows as $sms) {
                $this->linkSmsToGroup($sms, $group->id, $clearedAt);
            }

            $anchorSms = $smsRows->first();
            $anchorOps = $opsRows->first();

            if ($anchorSms instanceof SmsTransaction && $anchorOps instanceof BankTransaction) {
                $this->postMasterBankLedgerForSmsOpsMatch($anchorSms->fresh(), $anchorOps->fresh());
            }

            $this->audit->log('SMS_OPS_MATCH_GROUP_LINKED', 'sms_clearing', $group, $anchorSms?->member, [
                'direction' => $shape,
                'operational_bank_transaction_ids' => $opsRows->pluck('id')->all(),
                'sms_transaction_ids' => $smsRows->pluck('id')->all(),
                'sms_ops_clearance_match_group_id' => $group->id,
            ]);
        });
    }

    public function unmatchClearedGroup(BankTransaction|SmsTransaction $anyMember): void
    {
        $groupId = $anyMember instanceof BankTransaction
            ? $anyMember->sms_ops_clearance_match_group_id
            : $anyMember->sms_ops_clearance_match_group_id;

        if ($groupId === null) {
            throw new InvalidArgumentException(__('This match group could not be found.'));
        }

        $opsMembers = BankTransaction::query()->where('sms_ops_clearance_match_group_id', $groupId)->get();
        $smsMembers = SmsTransaction::query()->where('sms_ops_clearance_match_group_id', $groupId)->get();

        if ($opsMembers->isEmpty() && $smsMembers->isEmpty()) {
            throw new InvalidArgumentException(__('This match group could not be found.'));
        }

        DB::transaction(function () use ($opsMembers, $smsMembers, $groupId): void {
            $this->audit->log('SMS_OPS_MATCH_UNMATCHED', 'sms_clearing', SmsOpsClearanceMatchGroup::query()->find($groupId), null, [
                'sms_ops_clearance_match_group_id' => $groupId,
                'operational_bank_transaction_ids' => $opsMembers->pluck('id')->all(),
                'sms_transaction_ids' => $smsMembers->pluck('id')->all(),
            ]);

            foreach ($opsMembers as $ops) {
                $ops->update([
                    'sms_ops_clearance_match_group_id' => null,
                    'is_cleared' => false,
                    'cleared_at' => null,
                ]);
            }

            foreach ($smsMembers as $sms) {
                $this->maybeReverseMasterBankLedgerForSmsUnmatch($sms);
                $sms->update([
                    'sms_ops_clearance_match_group_id' => null,
                    'is_ops_cleared' => false,
                    'ops_cleared_at' => null,
                ]);
            }
        });
    }

    /**
     * @return EloquentCollection<int, BankTransaction>
     */
    public function findGroupMatchOpsCandidates(SmsTransaction $sms): EloquentCollection
    {
        if (! $this->isSmsOpsMatchEligible($sms)) {
            return BankTransaction::query()->whereRaw('0 = 1')->get();
        }

        return $this->bankClearing
            ->applyPendingOperationalClearanceScope(BankTransaction::query())
            ->whereNull('sms_ops_clearance_match_group_id')
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit(200)
            ->get();
    }

    /**
     * @return EloquentCollection<int, SmsTransaction>
     */
    public function findGroupMatchSmsCandidates(BankTransaction $ops): EloquentCollection
    {
        if (! $this->isOpsMatchEligible($ops)) {
            return SmsTransaction::query()->whereRaw('0 = 1')->get();
        }

        return SmsTransaction::query()
            ->where('is_duplicate', false)
            ->where('is_ops_cleared', false)
            ->whereNull('sms_ops_clearance_match_group_id')
            ->whereNotNull('posted_at')
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit(200)
            ->get();
    }

    /**
     * @return array{matched: int, ambiguous: int, unmatched_ops: int}
     */
    public function autoMatchUniquePairs(): array
    {
        if (! $this->isEnabled()) {
            return ['matched' => 0, 'ambiguous' => 0, 'unmatched_ops' => 0];
        }

        $tolerance = ContributionPolicySettings::reconTolerance();
        $dateRange = ContributionPolicySettings::bankMatchDateRangeDays();
        $matched = 0;
        $ambiguous = 0;

        $pendingOps = $this->bankClearing
            ->applyPendingOperationalClearanceScope(BankTransaction::query())
            ->whereNull('sms_ops_clearance_match_group_id')
            ->orderBy('id')
            ->get();

        foreach ($pendingOps as $ops) {
            $candidates = $this->findOneToOneSmsCandidates($ops, $tolerance, $dateRange);

            if ($candidates->isEmpty()) {
                continue;
            }

            if ($candidates->count() > 1) {
                $ambiguous++;

                continue;
            }

            $sms = $candidates->first();

            if (! $sms instanceof SmsTransaction) {
                continue;
            }

            try {
                $this->clearMatchPair($ops, $sms);
                $matched++;
            } catch (InvalidArgumentException) {
                $ambiguous++;
            }
        }

        return [
            'matched' => $matched,
            'ambiguous' => $ambiguous,
            'unmatched_ops' => max(0, $pendingOps->count() - $matched),
        ];
    }

    /**
     * @return array{
     *     ambiguous: list<array{operational_bank_transaction_id: int, candidate_ids: list<int>}>,
     *     unmatched_sms: list<int>,
     * }
     */
    public function scanMatchExceptions(): array
    {
        if (! $this->isEnabled()) {
            return ['ambiguous' => [], 'unmatched_sms' => []];
        }

        $tolerance = ContributionPolicySettings::reconTolerance();
        $dateRange = ContributionPolicySettings::bankMatchDateRangeDays();
        $ambiguous = [];
        $unmatchedSms = [];

        SmsTransaction::query()
            ->where('is_duplicate', false)
            ->where('is_ops_cleared', false)
            ->whereNotNull('posted_at')
            ->whereNull('sms_ops_clearance_match_group_id')
            ->orderBy('id')
            ->each(function (SmsTransaction $sms) use ($tolerance, $dateRange, &$ambiguous, &$unmatchedSms): void {
                $candidates = $this->findOneToOneOpsCandidates($sms, $tolerance, $dateRange);

                if ($candidates->isEmpty()) {
                    $unmatchedSms[] = $sms->id;

                    return;
                }

                if ($candidates->count() > 1) {
                    $ambiguous[] = [
                        'sms_transaction_id' => $sms->id,
                        'candidate_ids' => $candidates->pluck('id')->all(),
                    ];
                }
            });

        return [
            'ambiguous' => $ambiguous,
            'unmatched_sms' => $unmatchedSms,
        ];
    }

    /**
     * @return array{
     *     one_to_many: list<array{operational_bank_transaction_id: int, sms_transaction_ids: list<int>}>,
     *     many_to_one: list<array{sms_transaction_id: int, operational_bank_transaction_ids: list<int>}>,
     * }
     */
    public function scanGroupMatchHints(): array
    {
        if (! $this->isEnabled()) {
            return ['one_to_many' => [], 'many_to_one' => []];
        }

        return [
            'one_to_many' => [],
            'many_to_one' => [],
        ];
    }

    public function formatOpsMatchOptionLabel(BankTransaction $ops): string
    {
        $amount = number_format(abs((float) $ops->amount), 2);
        $date = $ops->transaction_date?->format('Y-m-d') ?? '—';
        $ref = filled($ops->reference) ? $ops->reference : ($ops->description ?? __('Operational'));

        return __(':date · :amount · :ref', [
            'date' => $date,
            'amount' => $amount,
            'ref' => $ref,
        ]);
    }

    public function formatSmsMatchOptionLabel(SmsTransaction $sms): string
    {
        $amount = number_format(abs((float) $sms->amount), 2);
        $date = $sms->transaction_date?->format('Y-m-d') ?? '—';
        $member = $sms->member?->name ?? __('Unassigned');

        return __(':date · :amount · :member', [
            'date' => $date,
            'amount' => $amount,
            'member' => $member,
        ]);
    }

    public function pairAmountsMatch(BankTransaction $ops, SmsTransaction $sms, ?float $tolerance = null): bool
    {
        $tolerance ??= ContributionPolicySettings::reconTolerance();

        return abs(abs((float) $ops->amount) - abs((float) $sms->amount)) <= $tolerance;
    }

    /**
     * @param  Collection<int, BankTransaction>  $opsRows
     * @param  Collection<int, SmsTransaction>  $smsRows
     */
    public function groupAmountsMatch(Collection $opsRows, Collection $smsRows, ?float $tolerance = null): bool
    {
        $tolerance ??= ContributionPolicySettings::reconTolerance();
        $opsTotal = $opsRows->sum(fn (BankTransaction $row): float => abs((float) $row->amount));
        $smsTotal = $smsRows->sum(fn (SmsTransaction $row): float => abs((float) $row->amount));

        return abs($opsTotal - $smsTotal) <= $tolerance;
    }

    private function clearOperationalRow(BankTransaction $ops): void
    {
        $this->clearance->markClearedWithoutEvidence($ops);

        if ($ops->fund_posting_id !== null) {
            $fundPosting = $ops->fundPosting()->with('member.user')->first();
            $memberUser = $fundPosting?->member?->user;

            if ($memberUser !== null && $fundPosting !== null) {
                $memberUser->notify(new FundPostingBankClearedNotification($fundPosting));
            }

            return;
        }

        if ($ops->cash_out_request_id !== null) {
            $cashOutRequest = $ops->cashOutRequest()->with('member.user')->first();
            $memberUser = $cashOutRequest?->member?->user;

            if ($memberUser !== null && $cashOutRequest !== null) {
                $memberUser->notify(new CashOutBankClearedNotification($cashOutRequest));
            }
        }
    }

    private function postMasterBankLedgerForSmsOpsMatch(SmsTransaction $sms, BankTransaction $ops): void
    {
        if (! $sms->isPosted()) {
            return;
        }

        if ($this->shouldSkipMasterBankLedgerForOperational($ops)) {
            return;
        }

        if ($sms->master_bank_transaction_id !== null) {
            return;
        }

        $masterBank = Account::masterBank();

        if ($masterBank === null) {
            return;
        }

        $amount = abs((float) $sms->amount);

        if ($amount <= 0.00001) {
            return;
        }

        $description = __('SMS evidence: :reference', [
            'reference' => $sms->reference ?: ($sms->raw_sms ?: __('Transfer')),
        ]);
        $memberId = $sms->member_id;
        $transactedAt = $sms->transaction_date ?? BusinessDay::now();
        $isCredit = $sms->transaction_type !== 'debit';

        $ledger = $isCredit
            ? $this->accounting->credit($masterBank, $amount, $description, $sms, $transactedAt, $memberId)
            : $this->accounting->debit($masterBank, $amount, $description, $sms, $transactedAt, $memberId);

        $sms->forceFill(['master_bank_transaction_id' => $ledger->id])->saveQuietly();
    }

    private function maybeReverseMasterBankLedgerForSmsUnmatch(SmsTransaction $sms): void
    {
        if ($sms->master_bank_transaction_id === null) {
            return;
        }

        $ledger = Transaction::query()->find($sms->master_bank_transaction_id);

        if ($ledger === null || $this->accounting->hasExistingReversal($ledger)) {
            return;
        }

        AccountingService::withoutMemberCashCollection(
            fn () => $this->accounting->createReversalEntry(
                $ledger,
                __('Unmatch SMS operational link'),
            ),
        );

        $sms->update(['master_bank_transaction_id' => null]);
    }

    private function shouldSkipMasterBankLedgerForOperational(BankTransaction $ops): bool
    {
        return $ops->expense_disbursement_id !== null
            || $ops->fee_disbursement_id !== null
            || $ops->invest_disbursement_id !== null
            || $ops->invest_return_id !== null;
    }

    private function linkOpsToGroup(BankTransaction $ops, int $groupId): void
    {
        if ($ops->sms_ops_clearance_match_group_id === $groupId) {
            return;
        }

        $ops->update(['sms_ops_clearance_match_group_id' => $groupId]);
    }

    private function linkSmsToGroup(SmsTransaction $sms, int $groupId, CarbonInterface $clearedAt): void
    {
        if ($sms->sms_ops_clearance_match_group_id === $groupId) {
            return;
        }

        $sms->update([
            'sms_ops_clearance_match_group_id' => $groupId,
            'is_ops_cleared' => true,
            'ops_cleared_at' => $clearedAt,
        ]);
    }

    private function assertOpsEligible(BankTransaction $ops): void
    {
        if (! $this->isOpsMatchEligible($ops)) {
            throw new InvalidArgumentException(__('The operational row is not eligible for SMS matching.'));
        }
    }

    private function assertSmsEligible(SmsTransaction $sms): void
    {
        if (! $this->isSmsOpsMatchEligible($sms)) {
            throw new InvalidArgumentException(__('The SMS row is not eligible for operational matching.'));
        }
    }

    /**
     * @return EloquentCollection<int, SmsTransaction>
     */
    private function findOneToOneSmsCandidates(BankTransaction $ops, float $tolerance, int $dateRange): EloquentCollection
    {
        if (! $this->isOpsMatchEligible($ops)) {
            return SmsTransaction::query()->whereRaw('0 = 1')->get();
        }

        $amount = abs((float) $ops->amount);
        $opsDate = $ops->transaction_date;

        $query = SmsTransaction::query()
            ->where('is_duplicate', false)
            ->where('is_ops_cleared', false)
            ->whereNull('sms_ops_clearance_match_group_id')
            ->whereNotNull('posted_at')
            ->whereRaw('ABS(amount) BETWEEN ? AND ?', [$amount - $tolerance, $amount + $tolerance]);

        if ($dateRange > 0 && $opsDate !== null) {
            $query->whereBetween('transaction_date', [
                $opsDate->copy()->subDays($dateRange)->toDateString(),
                $opsDate->copy()->addDays($dateRange)->toDateString(),
            ]);
        }

        return $query->orderByDesc('transaction_date')->get();
    }

    /**
     * @return EloquentCollection<int, BankTransaction>
     */
    private function findOneToOneOpsCandidates(SmsTransaction $sms, float $tolerance, int $dateRange): EloquentCollection
    {
        if (! $this->isSmsOpsMatchEligible($sms)) {
            return BankTransaction::query()->whereRaw('0 = 1')->get();
        }

        $amount = abs((float) $sms->amount);
        $smsDate = $sms->transaction_date;

        $query = $this->bankClearing
            ->applyPendingOperationalClearanceScope(BankTransaction::query())
            ->whereNull('sms_ops_clearance_match_group_id')
            ->whereRaw('ABS(amount) BETWEEN ? AND ?', [$amount - $tolerance, $amount + $tolerance]);

        if ($dateRange > 0 && $smsDate !== null) {
            $query->whereBetween('transaction_date', [
                $smsDate->copy()->subDays($dateRange)->toDateString(),
                $smsDate->copy()->addDays($dateRange)->toDateString(),
            ]);
        }

        return $query->orderByDesc('transaction_date')->get();
    }
}
