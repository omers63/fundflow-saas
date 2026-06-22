<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\BankTransaction;
use App\Support\BankClearing\BankClearingQueueFilter;
use App\Support\BankTransactionWorkflow;
use Illuminate\Database\Eloquent\Builder;

final class BankClearingQueueService
{
    public function __construct(
        protected BankClearingMatchService $matching,
    ) {}

    /**
     * @param  Builder<BankTransaction>  $query
     * @return Builder<BankTransaction>
     */
    public function applyOpenItemsScope(Builder $query, BankClearingQueueFilter|string|null $filter = null): Builder
    {
        $filter = $filter instanceof BankClearingQueueFilter
            ? $filter
            : BankClearingQueueFilter::fromMixed(is_string($filter) ? $filter : null);

        return match ($filter) {
            BankClearingQueueFilter::BankFile => $this->matching->applyBankLinesAwaitingPostingScope($query),
            BankClearingQueueFilter::Operations => $this->matching->applyPendingOperationalClearanceScope($query),
            BankClearingQueueFilter::All => $query->where(function (Builder $outer): void {
                $outer->where(function (Builder $bankFile): void {
                    $this->matching->applyBankLinesAwaitingPostingScope($bankFile);
                })->orWhere(function (Builder $operations): void {
                    $this->matching->applyPendingOperationalClearanceScope($operations);
                });
            }),
        };
    }

    /**
     * @return Builder<BankTransaction>
     */
    public function openItemsQuery(BankClearingQueueFilter|string|null $filter = null): Builder
    {
        return $this->applyOpenItemsScope(BankTransaction::query(), $filter);
    }

    /**
     * @return array{bank_file: int, operations: int, all: int}
     */
    public function counts(): array
    {
        $bankFile = $this->matching->bankLinesAwaitingPostingCount();
        $operations = $this->matching->pendingOperationalClearanceCount();

        return [
            'bank_file' => $bankFile,
            'operations' => $operations,
            'all' => $bankFile + $operations,
        ];
    }

    public function openCount(): int
    {
        return $this->counts()['all'];
    }

    public function isBankFileItem(BankTransaction $record): bool
    {
        return $this->matching
            ->applyBankLinesAwaitingPostingScope(BankTransaction::query()->whereKey($record->getKey()))
            ->exists();
    }

    public function isOperationsItem(BankTransaction $record): bool
    {
        return $this->matching
            ->applyPendingOperationalClearanceScope(BankTransaction::query()->whereKey($record->getKey()))
            ->exists();
    }

    /**
     * @return 'bank_file'|'operations'
     */
    public function sliceForRecord(BankTransaction $record): string
    {
        if ($this->isOperationsItem($record)) {
            return 'operations';
        }

        return 'bank_file';
    }

    public function primaryActionForRecord(BankTransaction $record): ?string
    {
        if ($this->isOperationsItem($record)) {
            if ($this->matching->findUniqueCandidate($record) !== null) {
                return 'autoMatch';
            }

            return 'matchToBankLine';
        }

        if (BankTransactionWorkflow::canPostToCash($record)) {
            return 'mirrorToCash';
        }

        if (BankTransactionWorkflow::canPostToMember($record)) {
            return 'postToMember';
        }

        if ($this->matching->isPendingClearance($record)) {
            return 'matchToBankLine';
        }

        return null;
    }
}
