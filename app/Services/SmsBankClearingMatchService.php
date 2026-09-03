<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\SmsClearanceMatchGroup;
use App\Models\Tenant\SmsTransaction;
use App\Models\Tenant\Transaction;
use App\Support\BusinessDay;
use App\Support\ContributionPolicySettings;
use App\Support\EvidenceChannelSettings;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * SMS ↔ bank CSV line matching (1:1 and 1→N / N→1 groups).
 *
 * Links evidence only — never re-posts member cash for already-posted SMS rows.
 */
class SmsBankClearingMatchService
{
    public function __construct(
        private BankClearingMatchService $bankClearing,
        private AccountingService $accounting,
        private FundAuditLogService $audit,
    ) {}

    public function linkMatchGroupCount(): int
    {
        return SmsClearanceMatchGroup::query()->count();
    }

    public function isSmsMatchEligible(SmsTransaction $sms): bool
    {
        if (! EvidenceChannelSettings::usesBankCsv()) {
            return false;
        }

        if ($sms->is_duplicate || $sms->is_bank_cleared) {
            return false;
        }

        if ($sms->sms_clearance_match_group_id !== null) {
            return false;
        }

        if (! $sms->isPosted() && $sms->member_id === null) {
            return false;
        }

        return $sms->amount !== null && abs((float) $sms->amount) > 0.00001;
    }

    public function isBankMatchEligible(BankTransaction $bank): bool
    {
        if ($bank->duplicate_of_id !== null || $bank->sms_clearance_match_group_id !== null) {
            return false;
        }

        if ($this->bankClearing->isSyntheticOperationalStatement($bank)) {
            return false;
        }

        return $this->bankClearing->applyRealBankStatementLinesScope(BankTransaction::query()->whereKey($bank->id))->exists();
    }

    public function clearMatchPair(SmsTransaction $sms, BankTransaction $bank): void
    {
        $this->assertSmsEligible($sms);
        $this->assertBankEligible($bank);

        if (! $this->pairAmountsMatch($sms, $bank)) {
            throw new InvalidArgumentException(__('SMS and bank line amounts do not balance within tolerance.'));
        }

        DB::transaction(function () use ($sms, $bank): void {
            $clearedAt = BusinessDay::now();
            $group = SmsClearanceMatchGroup::query()->create(['cleared_at' => $clearedAt]);

            $this->linkSmsToGroup($sms, $group->id, $clearedAt);
            $this->linkBankToGroup($bank, $group->id);
            $this->maybePostMasterBankLedgerForSmsMatch($sms, $bank);

            $this->audit->log('SMS_BANK_MATCH_LINKED', 'sms_clearing', $group, $sms->member, [
                'sms_transaction_id' => $sms->id,
                'bank_transaction_id' => $bank->id,
                'sms_clearance_match_group_id' => $group->id,
            ]);
        });
    }

    /**
     * @param  Collection<int, SmsTransaction>  $smsRows
     * @param  Collection<int, BankTransaction>  $bankRows
     */
    public function clearMatchGroup(Collection $smsRows, Collection $bankRows): void
    {
        $smsRows = $smsRows->values();
        $bankRows = $bankRows->values();

        if ($smsRows->isEmpty() || $bankRows->isEmpty()) {
            throw new InvalidArgumentException(__('Select at least one SMS row and one bank import line.'));
        }

        $shape = match (true) {
            $smsRows->count() === 1 && $bankRows->count() === 1 => null,
            $smsRows->count() === 1 && $bankRows->count() >= 2 => 'one_to_many',
            $bankRows->count() === 1 && $smsRows->count() >= 2 => 'many_to_one',
            $smsRows->count() >= 2 && $bankRows->count() >= 2 => 'many_to_many',
            default => null,
        };

        if ($shape === null) {
            throw new InvalidArgumentException(__('Group match requires two or more rows on at least one side, or use Match for a single pair.'));
        }

        foreach ($smsRows as $row) {
            if (! $row instanceof SmsTransaction) {
                throw new InvalidArgumentException(__('One or more SMS rows are not eligible for bank matching.'));
            }

            $this->assertSmsEligible($row);
        }

        foreach ($bankRows as $row) {
            if (! $row instanceof BankTransaction) {
                throw new InvalidArgumentException(__('One or more bank import lines are not eligible for matching.'));
            }

            $this->assertBankEligible($row);
        }

        if (! $this->groupAmountsMatch($smsRows, $bankRows)) {
            throw new InvalidArgumentException(__('Selected amounts do not balance within tolerance.'));
        }

        DB::transaction(function () use ($smsRows, $bankRows, $shape): void {
            $clearedAt = BusinessDay::now();
            $group = SmsClearanceMatchGroup::query()->create(['cleared_at' => $clearedAt]);

            if ($shape === 'many_to_many') {
                $postedSms = $smsRows->first(fn (SmsTransaction $sms): bool => $sms->isPosted());

                foreach ($smsRows as $sms) {
                    $this->linkSmsToGroup($sms, $group->id, $clearedAt);
                }

                foreach ($bankRows as $bank) {
                    $this->linkBankToGroup($bank, $group->id);

                    if ($postedSms !== null) {
                        $this->maybePostMasterBankLedgerForSmsMatch($postedSms, $bank);
                    }
                }

                $this->audit->log('SMS_BANK_MATCH_GROUP_LINKED', 'sms_clearing', $group, $postedSms?->member, [
                    'direction' => 'many_to_many',
                    'sms_transaction_ids' => $smsRows->pluck('id')->all(),
                    'bank_transaction_ids' => $bankRows->pluck('id')->all(),
                    'sms_clearance_match_group_id' => $group->id,
                ]);

                return;
            }

            $oneSms = $shape === 'one_to_many';

            if ($oneSms) {
                $anchorSms = $smsRows->first();
                $this->linkSmsToGroup($anchorSms, $group->id, $clearedAt);

                foreach ($bankRows as $bank) {
                    $this->linkBankToGroup($bank, $group->id);
                    $this->maybePostMasterBankLedgerForSmsMatch($anchorSms, $bank);
                }

                $this->audit->log('SMS_BANK_MATCH_GROUP_LINKED', 'sms_clearing', $group, $anchorSms?->member, [
                    'direction' => 'one_to_many',
                    'sms_transaction_ids' => $smsRows->pluck('id')->all(),
                    'bank_transaction_ids' => $bankRows->pluck('id')->all(),
                    'sms_clearance_match_group_id' => $group->id,
                ]);

                return;
            }

            $anchorBank = $bankRows->first();
            $this->linkBankToGroup($anchorBank, $group->id);

            $postedSms = $smsRows->first(fn (SmsTransaction $sms): bool => $sms->isPosted());

            foreach ($smsRows as $sms) {
                $this->linkSmsToGroup($sms, $group->id, $clearedAt);
            }

            if ($postedSms !== null) {
                $this->maybePostMasterBankLedgerForSmsMatch($postedSms, $anchorBank);
            }

            $this->audit->log('SMS_BANK_MATCH_GROUP_LINKED', 'sms_clearing', $group, $postedSms?->member, [
                'direction' => 'many_to_one',
                'sms_transaction_ids' => $smsRows->pluck('id')->all(),
                'bank_transaction_ids' => $bankRows->pluck('id')->all(),
                'sms_clearance_match_group_id' => $group->id,
            ]);
        });
    }

    public function unmatchClearedRow(SmsTransaction|BankTransaction $record): void
    {
        if ($record instanceof SmsTransaction) {
            if (! $record->is_bank_cleared) {
                throw new InvalidArgumentException(__('This SMS row is not linked to a bank line.'));
            }

            if ($record->sms_clearance_match_group_id !== null) {
                $this->unmatchClearedGroup($record);

                return;
            }

            throw new InvalidArgumentException(__('This SMS row is not linked to a bank line.'));
        }

        if ($record->sms_clearance_match_group_id === null) {
            throw new InvalidArgumentException(__('This bank line is not linked to an SMS row.'));
        }

        $this->unmatchClearedGroup($record);
    }

    public function unmatchClearedGroup(SmsTransaction|BankTransaction $anyMember): void
    {
        $groupId = $anyMember instanceof SmsTransaction
            ? $anyMember->sms_clearance_match_group_id
            : $anyMember->sms_clearance_match_group_id;

        if ($groupId === null) {
            throw new InvalidArgumentException(__('This match group could not be found.'));
        }

        $smsMembers = SmsTransaction::query()->where('sms_clearance_match_group_id', $groupId)->get();
        $bankMembers = BankTransaction::query()->where('sms_clearance_match_group_id', $groupId)->get();

        if ($smsMembers->isEmpty() && $bankMembers->isEmpty()) {
            throw new InvalidArgumentException(__('This match group could not be found.'));
        }

        DB::transaction(function () use ($smsMembers, $bankMembers, $groupId): void {
            $this->audit->log('SMS_BANK_MATCH_UNMATCHED', 'sms_clearing', SmsClearanceMatchGroup::query()->find($groupId), null, [
                'sms_clearance_match_group_id' => $groupId,
                'sms_transaction_ids' => $smsMembers->pluck('id')->all(),
                'bank_transaction_ids' => $bankMembers->pluck('id')->all(),
            ]);

            foreach ($bankMembers as $bank) {
                $this->maybeReverseMasterBankLedgerForSmsUnmatch($bank);
                $bank->update(['sms_clearance_match_group_id' => null]);
            }

            foreach ($smsMembers as $sms) {
                $sms->update([
                    'sms_clearance_match_group_id' => null,
                    'is_bank_cleared' => false,
                    'bank_cleared_at' => null,
                ]);
            }
        });
    }

    /**
     * @return EloquentCollection<int, BankTransaction>
     */
    public function findGroupMatchBankCandidates(SmsTransaction $sms): EloquentCollection
    {
        if (! $this->isSmsMatchEligible($sms)) {
            return BankTransaction::query()->whereRaw('0 = 1')->get();
        }

        return $this->bankClearing
            ->applyRealBankStatementLinesScope(BankTransaction::query())
            ->whereNull('sms_clearance_match_group_id')
            ->whereNull('duplicate_of_id')
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit(200)
            ->get();
    }

    /**
     * @return EloquentCollection<int, SmsTransaction>
     */
    public function findGroupMatchSmsCandidates(BankTransaction $bank): EloquentCollection
    {
        if (! $this->isBankMatchEligible($bank)) {
            return SmsTransaction::query()->whereRaw('0 = 1')->get();
        }

        return SmsTransaction::query()
            ->where('is_duplicate', false)
            ->where('is_bank_cleared', false)
            ->whereNull('sms_clearance_match_group_id')
            ->where(function (Builder $query): void {
                $query->whereNotNull('posted_at')
                    ->orWhereNotNull('member_id');
            })
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit(200)
            ->get();
    }

    /**
     * @return array{
     *     ambiguous: list<array{sms_transaction_id: int, candidate_ids: list<int>}>,
     *     unmatched_sms: list<int>,
     * }
     */
    public function scanMatchExceptions(): array
    {
        $tolerance = ContributionPolicySettings::reconTolerance();
        $ambiguous = [];
        $unmatchedSms = [];

        $smsRows = SmsTransaction::query()
            ->where('is_duplicate', false)
            ->where('is_bank_cleared', false)
            ->whereNotNull('posted_at')
            ->get();

        foreach ($smsRows as $sms) {
            $candidates = $this->findOneToOneBankCandidates($sms, $tolerance);

            if ($candidates->count() === 1) {
                continue;
            }

            if ($candidates->count() > 1) {
                $ambiguous[] = [
                    'sms_transaction_id' => $sms->id,
                    'candidate_ids' => $candidates->pluck('id')->all(),
                ];

                continue;
            }

            $unmatchedSms[] = $sms->id;
        }

        return [
            'ambiguous' => $ambiguous,
            'unmatched_sms' => $unmatchedSms,
        ];
    }

    /**
     * @return array{
     *     one_to_many: list<array{sms_transaction_id: int, bank_transaction_ids: list<int>}>,
     *     many_to_one: list<array{bank_transaction_id: int, sms_transaction_ids: list<int>}>,
     *     many_to_many: list<array{sms_transaction_ids: list<int>, bank_transaction_ids: list<int>}>,
     *     hint_bank_ids: list<int>,
     * }
     */
    public function scanGroupMatchHints(): array
    {
        $tolerance = ContributionPolicySettings::reconTolerance();
        $oneToMany = [];
        $manyToOne = [];
        $manyToMany = [];
        $hintBankIds = [];
        $hintSmsIds = [];

        $smsRows = SmsTransaction::query()
            ->where('is_duplicate', false)
            ->where('is_bank_cleared', false)
            ->where(function (Builder $query): void {
                $query->whereNotNull('posted_at')
                    ->orWhereNotNull('member_id');
            })
            ->get();

        foreach ($smsRows as $sms) {
            if ($this->findOneToOneBankCandidates($sms, $tolerance)->count() === 1) {
                continue;
            }

            $candidates = $this->findGroupMatchBankCandidates($sms);

            if ($candidates->count() < 2) {
                continue;
            }

            $subset = $this->findBalancingBankSubset($candidates, (float) $sms->amount, $tolerance);

            if ($subset === null) {
                continue;
            }

            $oneToMany[] = [
                'sms_transaction_id' => $sms->id,
                'bank_transaction_ids' => $subset,
            ];

            $hintSmsIds[(int) $sms->id] = true;

            foreach ($subset as $bankId) {
                $hintBankIds[(int) $bankId] = true;
            }
        }

        $bankRows = $this->bankClearing
            ->applyRealBankStatementLinesScope(BankTransaction::query())
            ->whereNull('sms_clearance_match_group_id')
            ->whereNull('duplicate_of_id')
            ->get();

        foreach ($bankRows as $bank) {
            if ($this->findOneToOneSmsCandidates($bank, $tolerance)->count() === 1) {
                continue;
            }

            $candidates = $this->findGroupMatchSmsCandidates($bank);

            if ($candidates->count() < 2) {
                continue;
            }

            $subset = $this->findBalancingSmsSubset($candidates, (float) $bank->amount, $tolerance);

            if ($subset === null) {
                continue;
            }

            $manyToOne[] = [
                'bank_transaction_id' => $bank->id,
                'sms_transaction_ids' => $subset,
            ];

            $hintBankIds[(int) $bank->id] = true;

            foreach ($subset as $smsId) {
                $hintSmsIds[(int) $smsId] = true;
            }
        }

        $smsItems = $smsRows
            ->reject(fn (SmsTransaction $row): bool => isset($hintSmsIds[(int) $row->id]))
            ->map(fn (SmsTransaction $sms): array => [
                'id' => $sms->id,
                'amount' => (float) $sms->amount,
            ])
            ->values()
            ->all();

        $bankItems = $bankRows
            ->reject(fn (BankTransaction $row): bool => isset($hintBankIds[(int) $row->id]))
            ->map(fn (BankTransaction $bank): array => [
                'id' => $bank->id,
                'amount' => (float) $bank->amount,
            ])
            ->values()
            ->all();

        foreach ($this->findManyToManySubsetHints($smsItems, $bankItems, $tolerance) as $hint) {
            $manyToMany[] = [
                'sms_transaction_ids' => $hint['left_ids'],
                'bank_transaction_ids' => $hint['right_ids'],
            ];

            foreach ($hint['left_ids'] as $smsId) {
                $hintSmsIds[(int) $smsId] = true;
            }

            foreach ($hint['right_ids'] as $bankId) {
                $hintBankIds[(int) $bankId] = true;
            }
        }

        return [
            'one_to_many' => $oneToMany,
            'many_to_one' => $manyToOne,
            'many_to_many' => $manyToMany,
            'hint_bank_ids' => array_keys($hintBankIds),
        ];
    }

    /**
     * @param  list<array{id: int, amount: float}>  $leftItems
     * @param  list<array{id: int, amount: float}>  $rightItems
     * @return list<array{left_ids: list<int>, right_ids: list<int>}>
     */
    private function findManyToManySubsetHints(array $leftItems, array $rightItems, float $tolerance): array
    {
        $hints = [];
        $leftCount = count($leftItems);

        for ($leftA = 0; $leftA < $leftCount; $leftA++) {
            for ($leftB = $leftA + 1; $leftB < $leftCount; $leftB++) {
                $leftSum = $leftItems[$leftA]['amount'] + $leftItems[$leftB]['amount'];
                $leftIds = [(int) $leftItems[$leftA]['id'], (int) $leftItems[$leftB]['id']];
                $rightCount = count($rightItems);

                for ($rightA = 0; $rightA < $rightCount; $rightA++) {
                    for ($rightB = $rightA + 1; $rightB < $rightCount; $rightB++) {
                        $rightSum = $rightItems[$rightA]['amount'] + $rightItems[$rightB]['amount'];

                        if (abs($leftSum - $rightSum) > $tolerance) {
                            continue;
                        }

                        $hints[] = [
                            'left_ids' => $leftIds,
                            'right_ids' => [
                                (int) $rightItems[$rightA]['id'],
                                (int) $rightItems[$rightB]['id'],
                            ],
                        ];
                    }
                }
            }
        }

        return $hints;
    }

    /**
     * @param  Collection<int, SmsTransaction>  $smsRows
     * @param  Collection<int, BankTransaction>  $bankRows
     */
    public function groupAmountsMatch(Collection $smsRows, Collection $bankRows, ?float $tolerance = null): bool
    {
        $tolerance ??= ContributionPolicySettings::reconTolerance();

        return abs($this->sumSmsAmounts($smsRows) - $this->sumBankAmounts($bankRows)) <= $tolerance;
    }

    /**
     * @param  Collection<int, SmsTransaction>  $smsRows
     */
    public function sumSmsAmounts(Collection $smsRows): float
    {
        return (float) $smsRows->sum(fn (SmsTransaction $sms): float => (float) $sms->amount);
    }

    /**
     * @param  Collection<int, BankTransaction>  $bankRows
     */
    public function sumBankAmounts(Collection $bankRows): float
    {
        return (float) $bankRows->sum(fn (BankTransaction $bank): float => (float) $bank->amount);
    }

    public function formatSmsMatchOptionLabel(SmsTransaction $sms): string
    {
        $date = $sms->transaction_date?->format('Y-m-d') ?? '—';
        $amount = number_format((float) $sms->amount, 2, '.', ',');
        $member = $sms->member?->name ?? __('Unassigned');
        $posted = $sms->isPosted() ? __('Posted') : __('Open');

        return "{$date} · {$amount} · {$member} · {$posted}";
    }

    /**
     * @return EloquentCollection<int, BankTransaction>
     */
    public function findManualBankCandidates(SmsTransaction $sms): EloquentCollection
    {
        $tolerance = ContributionPolicySettings::reconTolerance();

        return $this->findOneToOneBankCandidates($sms, $tolerance);
    }

    /**
     * @return EloquentCollection<int, SmsTransaction>
     */
    public function findManualSmsCandidates(BankTransaction $bank): EloquentCollection
    {
        $tolerance = ContributionPolicySettings::reconTolerance();

        return $this->findOneToOneSmsCandidates($bank, $tolerance);
    }

    private function assertSmsEligible(SmsTransaction $sms): void
    {
        if (! $this->isSmsMatchEligible($sms)) {
            throw new InvalidArgumentException(__('This SMS row is not eligible for bank matching.'));
        }
    }

    private function assertBankEligible(BankTransaction $bank): void
    {
        if (! $this->isBankMatchEligible($bank)) {
            throw new InvalidArgumentException(__('This bank import line is not eligible for SMS matching.'));
        }
    }

    private function pairAmountsMatch(SmsTransaction $sms, BankTransaction $bank, ?float $tolerance = null): bool
    {
        $tolerance ??= ContributionPolicySettings::reconTolerance();

        return abs((float) $sms->amount - (float) $bank->amount) <= $tolerance;
    }

    private function linkSmsToGroup(SmsTransaction $sms, int $groupId, CarbonInterface $clearedAt): void
    {
        $sms->update([
            'sms_clearance_match_group_id' => $groupId,
            'is_bank_cleared' => true,
            'bank_cleared_at' => $clearedAt,
        ]);
    }

    private function linkBankToGroup(BankTransaction $bank, int $groupId): void
    {
        if ($bank->sms_clearance_match_group_id === $groupId) {
            return;
        }

        $bank->update(['sms_clearance_match_group_id' => $groupId]);
    }

    private function maybePostMasterBankLedgerForSmsMatch(SmsTransaction $sms, BankTransaction $bank): void
    {
        if (! $sms->isPosted()) {
            return;
        }

        if ($this->bankHasOperationalClearance($bank)) {
            return;
        }

        $this->bankClearing->postMatchedImportToMasterBankLedger($bank->fresh());
    }

    private function maybeReverseMasterBankLedgerForSmsUnmatch(BankTransaction $bank): void
    {
        if ($this->bankHasOperationalClearance($bank)) {
            return;
        }

        if ($bank->master_bank_transaction_id === null) {
            return;
        }

        $ledger = Transaction::query()->find($bank->master_bank_transaction_id);

        if ($ledger === null || $this->accounting->hasExistingReversal($ledger)) {
            return;
        }

        AccountingService::withoutMemberCashCollection(
            fn () => $this->accounting->createReversalEntry(
                $ledger,
                __('Unmatch SMS bank link'),
            ),
        );

        $bank->update(['master_bank_transaction_id' => null]);
    }

    private function bankHasOperationalClearance(BankTransaction $bank): bool
    {
        return $bank->fund_posting_id !== null
            || $bank->cash_out_request_id !== null
            || $bank->membership_application_id !== null
            || $bank->expense_disbursement_id !== null
            || $bank->fee_disbursement_id !== null
            || $bank->invest_disbursement_id !== null
            || $bank->invest_return_id !== null;
    }

    /**
     * @return EloquentCollection<int, BankTransaction>
     */
    private function findOneToOneBankCandidates(SmsTransaction $sms, float $tolerance): EloquentCollection
    {
        if (! $this->isSmsMatchEligible($sms)) {
            return BankTransaction::query()->whereRaw('0 = 1')->get();
        }

        $amount = (float) $sms->amount;

        return $this->bankClearing
            ->applyRealBankStatementLinesScope(BankTransaction::query())
            ->whereNull('sms_clearance_match_group_id')
            ->whereNull('duplicate_of_id')
            ->whereBetween('amount', [$amount - $tolerance, $amount + $tolerance])
            ->orderByDesc('transaction_date')
            ->get();
    }

    /**
     * @return EloquentCollection<int, SmsTransaction>
     */
    private function findOneToOneSmsCandidates(BankTransaction $bank, float $tolerance): EloquentCollection
    {
        if (! $this->isBankMatchEligible($bank)) {
            return SmsTransaction::query()->whereRaw('0 = 1')->get();
        }

        $amount = (float) $bank->amount;

        return SmsTransaction::query()
            ->where('is_duplicate', false)
            ->where('is_bank_cleared', false)
            ->whereNull('sms_clearance_match_group_id')
            ->where(function (Builder $query): void {
                $query->whereNotNull('posted_at')
                    ->orWhereNotNull('member_id');
            })
            ->whereBetween('amount', [$amount - $tolerance, $amount + $tolerance])
            ->orderByDesc('transaction_date')
            ->get();
    }

    /**
     * @param  EloquentCollection<int, BankTransaction>  $candidates
     * @return list<int>|null
     */
    private function findBalancingBankSubset(
        EloquentCollection $candidates,
        float $target,
        float $tolerance,
        int $maxCandidates = 15,
    ): ?array {
        $items = $candidates
            ->take($maxCandidates)
            ->values()
            ->map(fn (BankTransaction $transaction): array => [
                'id' => $transaction->id,
                'amount' => (float) $transaction->amount,
            ])
            ->all();

        return $this->findBalancingSubsetFromItems($items, $target, $tolerance);
    }

    /**
     * @param  EloquentCollection<int, SmsTransaction>  $candidates
     * @return list<int>|null
     */
    private function findBalancingSmsSubset(
        EloquentCollection $candidates,
        float $target,
        float $tolerance,
        int $maxCandidates = 15,
    ): ?array {
        $items = $candidates
            ->take($maxCandidates)
            ->values()
            ->map(fn (SmsTransaction $transaction): array => [
                'id' => $transaction->id,
                'amount' => (float) $transaction->amount,
            ])
            ->all();

        return $this->findBalancingSubsetFromItems($items, $target, $tolerance);
    }

    /**
     * @param  list<array{id: int, amount: float}>  $items
     * @return list<int>|null
     */
    private function findBalancingSubsetFromItems(array $items, float $target, float $tolerance): ?array
    {
        $count = count($items);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $sum = $items[$i]['amount'] + $items[$j]['amount'];

                if (abs($sum - $target) <= $tolerance) {
                    return [(int) $items[$i]['id'], (int) $items[$j]['id']];
                }
            }
        }

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                for ($k = $j + 1; $k < $count; $k++) {
                    $sum = $items[$i]['amount'] + $items[$j]['amount'] + $items[$k]['amount'];

                    if (abs($sum - $target) <= $tolerance) {
                        return [
                            (int) $items[$i]['id'],
                            (int) $items[$j]['id'],
                            (int) $items[$k]['id'],
                        ];
                    }
                }
            }
        }

        return null;
    }
}
