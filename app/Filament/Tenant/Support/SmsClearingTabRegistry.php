<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use App\Filament\Tenant\Resources\SmsClearing\SmsClearingResource;
use App\Support\EvidenceChannelSettings;

final class SmsClearingTabRegistry
{
    public const TAB_QUEUE = 'queue';

    public const TAB_LEDGER = 'ledger';

    public const TAB_HISTORY = 'history';

    public const FILTER_ALL = 'all';

    public const FILTER_UNMATCHED = 'unmatched';

    public const FILTER_READY = 'ready_to_post';

    public const FILTER_UNMATCHED_BANK = 'unmatched_bank';

    public const FILTER_READY_TO_MATCH = 'ready_to_match';

    public const FILTER_UNMATCHED_OPS = 'unmatched_ops';

    public const FILTER_READY_TO_CLEAR_OPS = 'ready_to_clear_ops';

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
        $filters = [
            self::FILTER_ALL => __('All open'),
            self::FILTER_UNMATCHED => __('Unmatched member'),
            self::FILTER_READY => __('Ready to post'),
        ];

        if (EvidenceChannelSettings::usesBankCsv()) {
            $filters[self::FILTER_UNMATCHED_BANK] = __('Unmatched bank');
            $filters[self::FILTER_READY_TO_MATCH] = __('Ready to match bank');
        }

        if (EvidenceChannelSettings::usesSms()) {
            $filters[self::FILTER_UNMATCHED_OPS] = __('Unmatched ops');
            $filters[self::FILTER_READY_TO_CLEAR_OPS] = __('Ready to clear ops');
        }

        return $filters;
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
            self::FILTER_UNMATCHED, self::FILTER_READY,
            self::FILTER_UNMATCHED_BANK, self::FILTER_READY_TO_MATCH,
            self::FILTER_UNMATCHED_OPS, self::FILTER_READY_TO_CLEAR_OPS => (string) $filter,
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
