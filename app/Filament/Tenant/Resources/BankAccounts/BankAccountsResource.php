<?php

namespace App\Filament\Tenant\Resources\BankAccounts;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Support\UiLabelIcons;
use App\Filament\Tenant\Resources\BankAccounts\Pages\ListBankAccounts;
use App\Filament\Tenant\Resources\BankAccounts\Pages\ViewBankStatement;
use App\Filament\Tenant\Resources\BankAccounts\RelationManagers\BankTransactionsRelationManager;
use App\Filament\Tenant\Resources\BankAccounts\Tables\BankClearingQueueTable;
use App\Filament\Tenant\Resources\BankAccounts\Tables\BankStatementsTable;
use App\Filament\Tenant\Resources\BankAccounts\Tables\MasterBankLedgerTable;
use App\Filament\Tenant\Resources\SmsClearing\SmsClearingResource;
use App\Filament\Tenant\Support\BankClearingTabRegistry;
use App\Filament\Tenant\Support\SmsClearingTabRegistry;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\BankStatement;
use App\Services\BankClearingQueueService;
use BackedEnum;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Livewire\Livewire;
use UnitEnum;

class BankAccountsResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = BankStatement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_ACCOUNTS;

    protected static ?string $navigationLabel = 'Bank clearing';

    protected static ?string $modelLabel = 'Bank statement';

    protected static ?string $pluralModelLabel = 'Bank accounts';

    protected static ?string $slug = 'bank-accounts';

    protected static ?int $navigationSort = TenantNavigation::SORT_BANK_CLEARING;

    public static function getNavigationBadge(): ?string
    {
        try {
            $count = app(BankClearingQueueService::class)->openCount();

            return $count > 0 ? (string) $count : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $afterLedgerMutation = Livewire::current() instanceof ListRecords
            ? fn (): mixed => Livewire::current()->resetTable()
            : null;

        $tab = self::resolveListBankAccountsTab();

        if ($tab === BankClearingTabRegistry::TAB_LEDGER) {
            return MasterBankLedgerTable::configure(
                $table->pluralModelLabel(UiLabelIcons::tableModelLabel(__('Bank ledger'))),
                $afterLedgerMutation,
            );
        }

        if ($tab === BankClearingTabRegistry::TAB_HISTORY) {
            return BankStatementsTable::configure(
                $table->pluralModelLabel(UiLabelIcons::tableModelLabel(__('Import history'))),
            );
        }

        if ($tab === BankClearingTabRegistry::TAB_QUEUE) {
            return BankClearingQueueTable::configure(
                $table->pluralModelLabel(UiLabelIcons::tableModelLabel(__('Work queue'))),
            );
        }

        return BankStatementsTable::configure(
            $table->pluralModelLabel(UiLabelIcons::tableModelLabel(__('Import history'))),
        );
    }

    public static function resolveQueueFilter(): string
    {
        $livewire = Livewire::current();

        if ($livewire instanceof ListBankAccounts) {
            return BankClearingTabRegistry::normalizeQueueFilter($livewire->queueFilter);
        }

        $legacyTab = request()->string('tab')->toString();

        if (filled($legacyFilter = BankClearingTabRegistry::legacyTabQueueFilter($legacyTab ?: null))) {
            return $legacyFilter;
        }

        $filter = request()->string('queueFilter')->toString();

        return BankClearingTabRegistry::normalizeQueueFilter($filter ?: null);
    }

    public static function resolveHistorySection(): string
    {
        $livewire = Livewire::current();

        if ($livewire instanceof ListBankAccounts) {
            return BankClearingTabRegistry::normalizeHistorySection($livewire->historySection);
        }

        $section = request()->string('historySection')->toString();

        return BankClearingTabRegistry::normalizeHistorySection($section ?: null);
    }

    /**
     * Must stay aligned with {@see ListBankAccounts::getBankClearingTabs()} keys and URL query params.
     */
    public static function resolveListBankAccountsTab(): string
    {
        $livewire = Livewire::current();

        if ($livewire instanceof ListBankAccounts && filled($livewire->activeTab)) {
            $tab = $livewire->activeTab;
        } else {
            $tab = request()->string('tab')->toString() ?: BankClearingTabRegistry::TAB_QUEUE;
        }

        return BankClearingTabRegistry::normalizeTab($tab);
    }

    public static function resolveChannel(): string
    {
        $channel = request()->string('channel')->toString();

        return in_array($channel, ['bank', 'sms'], true) ? $channel : 'bank';
    }

    /**
     * @param  array<string, array<string, mixed>>  $filters
     */
    public static function listUrl(
        string $tab = BankClearingTabRegistry::TAB_QUEUE,
        array $filters = [],
        string $channel = 'bank',
        string $smsSubTab = 'transactions',
        ?string $queueFilter = null,
        ?string $historySection = null,
    ): string {
        if ($channel === 'sms') {
            $parameters = [];

            $normalizedTab = $smsSubTab === 'history'
                ? SmsClearingTabRegistry::TAB_HISTORY
                : SmsClearingTabRegistry::TAB_QUEUE;

            if ($normalizedTab !== SmsClearingTabRegistry::TAB_QUEUE) {
                $parameters['tab'] = $normalizedTab;
            }

            return SmsClearingResource::getUrl('index', $parameters);
        }

        $parameters = [];

        $normalizedTab = BankClearingTabRegistry::normalizeTab($tab);

        if ($channel === 'bank' && $normalizedTab !== BankClearingTabRegistry::TAB_QUEUE) {
            $parameters['tab'] = $normalizedTab;
        }

        if ($channel === 'bank' && $normalizedTab === BankClearingTabRegistry::TAB_QUEUE && filled($queueFilter)) {
            $normalizedFilter = BankClearingTabRegistry::normalizeQueueFilter($queueFilter);

            if ($normalizedFilter !== BankClearingTabRegistry::FILTER_ALL) {
                $parameters['queueFilter'] = $normalizedFilter;
            }
        }

        if ($channel === 'bank' && $normalizedTab === BankClearingTabRegistry::TAB_HISTORY && filled($historySection)) {
            $normalizedSection = BankClearingTabRegistry::normalizeHistorySection($historySection);

            if ($normalizedSection !== BankClearingTabRegistry::HISTORY_BATCHES) {
                $parameters['historySection'] = $normalizedSection;
            }
        }

        if ($filters !== []) {
            $parameters['filters'] = $filters;
        }

        return static::getUrl('index', $parameters);
    }

    public static function getRelations(): array
    {
        return [
            BankTransactionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankAccounts::route('/'),
            'view' => ViewBankStatement::route('/{record}'),
        ];
    }
}
