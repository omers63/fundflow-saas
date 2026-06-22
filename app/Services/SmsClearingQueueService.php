<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\SmsTransaction;
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

        $query = $query
            ->whereNull('posted_at')
            ->where('is_duplicate', false);

        return match ($filter) {
            SmsClearingQueueFilter::Unmatched => $query->whereNull('member_id'),
            SmsClearingQueueFilter::ReadyToPost => $query->whereNotNull('member_id'),
            SmsClearingQueueFilter::All => $query,
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
     * @return array{unmatched: int, ready_to_post: int, all: int}
     */
    public function counts(): array
    {
        $unmatched = $this->openItemsQuery(SmsClearingQueueFilter::Unmatched)->count();
        $ready = $this->openItemsQuery(SmsClearingQueueFilter::ReadyToPost)->count();

        return [
            'unmatched' => $unmatched,
            'ready_to_post' => $ready,
            'all' => $this->openItemsQuery(SmsClearingQueueFilter::All)->count(),
        ];
    }

    public function openCount(): int
    {
        return $this->counts()['all'];
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
