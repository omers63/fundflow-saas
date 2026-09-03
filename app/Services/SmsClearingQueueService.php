<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\SmsTransaction;
use App\Support\EvidenceChannelSettings;
use App\Support\SmsClearing\SmsClearingQueueFilter;
use Illuminate\Database\Eloquent\Builder;

final class SmsClearingQueueService
{
    /**
     * @param  Builder<SmsTransaction>  $query
     * @return Builder<SmsTransaction>
     */
    public function applyOpenItemsScope(Builder $query, SmsClearingQueueFilter|string|null $filter = null): Builder
    {
        $filter = $filter instanceof SmsClearingQueueFilter
            ? $filter
            : SmsClearingQueueFilter::fromMixed(is_string($filter) ? $filter : null);

        if (in_array($filter, [SmsClearingQueueFilter::UnmatchedOps, SmsClearingQueueFilter::ReadyToClearOps], true)) {
            return $this->applyPostedOpsScope($query, $filter);
        }

        $query = $query
            ->whereNull('posted_at')
            ->where('is_duplicate', false);

        return match ($filter) {
            SmsClearingQueueFilter::Unmatched => $query->whereNull('member_id'),
            SmsClearingQueueFilter::ReadyToPost => $query->whereNotNull('member_id'),
            SmsClearingQueueFilter::UnmatchedBank => $query
                ->where('is_bank_cleared', false)
                ->where(function (Builder $eligible): void {
                    $eligible->whereNotNull('posted_at')
                        ->orWhereNotNull('member_id');
                }),
            SmsClearingQueueFilter::ReadyToMatch => $query
                ->where('is_bank_cleared', false)
                ->whereNotNull('member_id')
                ->whereNotNull('amount'),
            SmsClearingQueueFilter::All => $query,
            default => $query,
        };
    }

    /**
     * @param  Builder<SmsTransaction>  $query
     * @return Builder<SmsTransaction>
     */
    private function applyPostedOpsScope(Builder $query, SmsClearingQueueFilter $filter): Builder
    {
        $query = $query
            ->where('is_duplicate', false)
            ->whereNotNull('posted_at')
            ->where('is_ops_cleared', false);

        return match ($filter) {
            SmsClearingQueueFilter::ReadyToClearOps => $query
                ->whereNotNull('member_id')
                ->whereNotNull('amount'),
            default => $query,
        };
    }

    /**
     * @return Builder<SmsTransaction>
     */
    public function openItemsQuery(SmsClearingQueueFilter|string|null $filter = null): Builder
    {
        return $this->applyOpenItemsScope(SmsTransaction::query(), $filter);
    }

    /**
     * @return array{unmatched: int, ready_to_post: int, unmatched_bank: int, ready_to_match: int, unmatched_ops: int, ready_to_clear_ops: int, all: int}
     */
    public function counts(): array
    {
        $unmatched = $this->openItemsQuery(SmsClearingQueueFilter::Unmatched)->count();
        $ready = $this->openItemsQuery(SmsClearingQueueFilter::ReadyToPost)->count();
        $unmatchedBank = EvidenceChannelSettings::usesBankCsv()
            ? $this->openItemsQuery(SmsClearingQueueFilter::UnmatchedBank)->count()
            : 0;
        $readyToMatch = EvidenceChannelSettings::usesBankCsv()
            ? $this->openItemsQuery(SmsClearingQueueFilter::ReadyToMatch)->count()
            : 0;
        $unmatchedOps = EvidenceChannelSettings::usesSms()
            ? $this->postedUnlinkedOpsCount()
            : 0;
        $readyToClearOps = EvidenceChannelSettings::usesSms()
            ? $this->openItemsQuery(SmsClearingQueueFilter::ReadyToClearOps)->count()
            : 0;

        return [
            'unmatched' => $unmatched,
            'ready_to_post' => $ready,
            'unmatched_bank' => $unmatchedBank,
            'ready_to_match' => $readyToMatch,
            'unmatched_ops' => $unmatchedOps,
            'ready_to_clear_ops' => $readyToClearOps,
            'all' => $this->openItemsQuery(SmsClearingQueueFilter::All)->count(),
        ];
    }

    public function openCount(): int
    {
        return $this->counts()['all'];
    }

    /**
     * Posted SMS rows with no bank evidence link.
     */
    public function postedUnlinkedBankCount(): int
    {
        if (! EvidenceChannelSettings::usesBankCsv()) {
            return 0;
        }

        return $this->postedUnlinkedBankQuery()->count();
    }

    public function postedUnlinkedOpsCount(): int
    {
        if (! EvidenceChannelSettings::usesSms()) {
            return 0;
        }

        return $this->postedUnlinkedOpsQuery()->count();
    }

    /**
     * @return Builder<SmsTransaction>
     */
    public function postedUnlinkedOpsQuery(): Builder
    {
        return SmsTransaction::query()
            ->where('is_duplicate', false)
            ->whereNotNull('posted_at')
            ->where('is_ops_cleared', false);
    }

    /**
     * @return Builder<SmsTransaction>
     */
    public function postedUnlinkedBankQuery(): Builder
    {
        return SmsTransaction::query()
            ->where('is_duplicate', false)
            ->whereNotNull('posted_at')
            ->where('is_bank_cleared', false);
    }

    public function isUnmatchedItem(SmsTransaction $record): bool
    {
        return $record->member_id === null;
    }

    public function isReadyToPostItem(SmsTransaction $record): bool
    {
        return $record->member_id !== null;
    }

    /**
     * @return 'unmatched'|'ready_to_post'
     */
    public function sliceForRecord(SmsTransaction $record): string
    {
        return $this->isUnmatchedItem($record) ? 'unmatched' : 'ready_to_post';
    }

    public function primaryActionForRecord(SmsTransaction $record): ?string
    {
        if ($record->isPosted() || $record->is_duplicate) {
            return null;
        }

        if ($this->isReadyToPostItem($record)) {
            return 'postToCash';
        }

        return 'postToCash';
    }
}
