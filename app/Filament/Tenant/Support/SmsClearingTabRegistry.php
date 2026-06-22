<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use App\Filament\Tenant\Resources\SmsClearing\SmsClearingResource;

final class SmsClearingTabRegistry
{
    public const TAB_QUEUE = 'queue';

    public const TAB_LEDGER = 'ledger';

    public const TAB_HISTORY = 'history';

    public const FILTER_ALL = 'all';

    public const FILTER_UNMATCHED = 'unmatched';

    public const FILTER_READY = 'ready_to_post';

    public const HISTORY_BATCHES = 'batches';

    public const HISTORY_DUPLICATES = 'duplicates';

    /**
     * @return array<string, string>
     */
    public static function tabs(): array
    {
        return [
            self::TAB_QUEUE => __('Work queue'),
            self::TAB_LEDGER => __('Posted ledger'),
            self::TAB_HISTORY => __('Import history'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function queueFilters(): array
    {
        return [
            self::FILTER_ALL => __('All open'),
            self::FILTER_UNMATCHED => __('Unmatched member'),
            self::FILTER_READY => __('Ready to post'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function historySections(): array
    {
        return [
            self::HISTORY_BATCHES => __('Import batches'),
            self::HISTORY_DUPLICATES => __('Duplicates'),
        ];
    }

    public static function normalizeTab(?string $tab): string
    {
        return match ($tab) {
            'transactions', 'imports', 'clearance' => self::TAB_QUEUE,
            'statements' => self::TAB_HISTORY,
            self::TAB_LEDGER, self::TAB_HISTORY, self::TAB_QUEUE => (string) $tab,
            default => self::TAB_QUEUE,
        };
    }

    public static function legacySmsSubTabQueueFilter(?string $smsSubTab): ?string
    {
        return match ($smsSubTab) {
            'transactions' => self::FILTER_ALL,
            default => null,
        };
    }

    public static function normalizeQueueFilter(?string $filter): string
    {
        return match ($filter) {
            self::FILTER_UNMATCHED, self::FILTER_READY => (string) $filter,
            default => self::FILTER_ALL,
        };
    }

    public static function normalizeHistorySection(?string $section): string
    {
        return match ($section) {
            self::HISTORY_DUPLICATES => self::HISTORY_DUPLICATES,
            default => self::HISTORY_BATCHES,
        };
    }

    public static function listUrl(
        string $tab = self::TAB_QUEUE,
        ?string $queueFilter = null,
        ?string $historySection = null,
    ): string {
        return SmsClearingResource::listUrl(
            tab: $tab,
            queueFilter: $queueFilter,
            historySection: $historySection,
        );
    }
}
