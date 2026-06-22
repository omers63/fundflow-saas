<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;

final class BankClearingTabRegistry
{
    public const TAB_QUEUE = 'queue';

    public const TAB_LEDGER = 'ledger';

    public const TAB_HISTORY = 'history';

    public const FILTER_ALL = 'all';

    public const FILTER_BANK_FILE = 'bank_file';

    public const FILTER_OPERATIONS = 'operations';

    public const HISTORY_BATCHES = 'batches';

    public const HISTORY_CLOSED = 'closed';

    /**
     * @return array<string, string>
     */
    public static function tabs(): array
    {
        return [
            self::TAB_QUEUE => __('Work queue'),
            self::TAB_LEDGER => __('Bank ledger'),
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
            self::FILTER_BANK_FILE => __('From bank file'),
            self::FILTER_OPERATIONS => __('From operations'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function historySections(): array
    {
        return [
            self::HISTORY_BATCHES => __('Import batches'),
            self::HISTORY_CLOSED => __('Closed lines'),
        ];
    }

    public static function normalizeTab(?string $tab): string
    {
        return match ($tab) {
            'clearance', 'imports', 'transactions' => self::TAB_QUEUE,
            'statements' => self::TAB_HISTORY,
            self::TAB_LEDGER, self::TAB_HISTORY, self::TAB_QUEUE, 'sms' => (string) $tab,
            default => self::TAB_QUEUE,
        };
    }

    public static function legacyTabQueueFilter(?string $legacyTab): ?string
    {
        return match ($legacyTab) {
            'clearance' => self::FILTER_OPERATIONS,
            'imports', 'transactions' => self::FILTER_BANK_FILE,
            default => null,
        };
    }

    public static function normalizeQueueFilter(?string $filter): string
    {
        return match ($filter) {
            self::FILTER_BANK_FILE, self::FILTER_OPERATIONS => (string) $filter,
            default => self::FILTER_ALL,
        };
    }

    public static function normalizeHistorySection(?string $section): string
    {
        return match ($section) {
            self::HISTORY_CLOSED => self::HISTORY_CLOSED,
            default => self::HISTORY_BATCHES,
        };
    }

    public static function listUrl(
        string $tab = self::TAB_QUEUE,
        ?string $queueFilter = null,
        ?string $historySection = null,
        string $channel = 'bank',
        string $smsSubTab = 'transactions',
    ): string {
        return BankAccountsResource::listUrl(
            tab: $tab,
            filters: [],
            channel: $channel,
            smsSubTab: $smsSubTab,
            queueFilter: $queueFilter,
            historySection: $historySection,
        );
    }
}
