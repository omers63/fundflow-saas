<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\SmsClearing;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Support\UiLabelIcons;
use App\Filament\Tenant\Resources\SmsClearing\Pages\ListSmsClearing;
use App\Filament\Tenant\Resources\SmsClearing\Tables\SmsClearingQueueTable;
use App\Filament\Tenant\Resources\SmsClearing\Tables\SmsPostedLedgerTable;
use App\Filament\Tenant\Support\SmsClearingTabRegistry;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\SmsTransaction;
use App\Services\SmsClearingQueueService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Livewire\Livewire;
use UnitEnum;

class SmsClearingResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = SmsTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_ACCOUNTS;

    protected static ?string $navigationLabel = 'SMS clearing';

    protected static ?string $modelLabel = 'SMS transaction';

    protected static ?string $pluralModelLabel = 'SMS clearing';

    protected static ?string $slug = 'sms-imports';

    protected static ?int $navigationSort = TenantNavigation::SORT_SMS_IMPORTS;

    public static function shouldRegisterNavigation(): bool
    {
        return DatabaseSchema::hasTable('sms_transactions')
            || DatabaseSchema::hasTable('sms_import_sessions');
    }

    public static function getNavigationBadge(): ?string
    {
        if (! DatabaseSchema::hasTable('sms_transactions')) {
            return null;
        }

        try {
            $count = app(SmsClearingQueueService::class)->openCount();

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
        $tab = self::resolveListSmsClearingTab();

        if ($tab === SmsClearingTabRegistry::TAB_LEDGER) {
            return SmsPostedLedgerTable::configure(
                $table->pluralModelLabel(UiLabelIcons::tableModelLabel(__('Posted ledger'))),
            );
        }

        if ($tab === SmsClearingTabRegistry::TAB_QUEUE) {
            return SmsClearingQueueTable::configure(
                $table->pluralModelLabel(UiLabelIcons::tableModelLabel(__('Work queue'))),
            );
        }

        return SmsClearingQueueTable::configure(
            $table->pluralModelLabel(UiLabelIcons::tableModelLabel(__('Work queue'))),
        );
    }

    public static function resolveQueueFilter(): string
    {
        $livewire = Livewire::current();

        if ($livewire instanceof ListSmsClearing) {
            return SmsClearingTabRegistry::normalizeQueueFilter($livewire->queueFilter);
        }

        $legacySubTab = request()->string('smsSubTab')->toString();

        if (filled($legacyFilter = SmsClearingTabRegistry::legacySmsSubTabQueueFilter($legacySubTab ?: null))) {
            return $legacyFilter;
        }

        $filter = request()->string('queueFilter')->toString();

        return SmsClearingTabRegistry::normalizeQueueFilter($filter ?: null);
    }

    public static function resolveHistorySection(): string
    {
        $livewire = Livewire::current();

        if ($livewire instanceof ListSmsClearing) {
            return SmsClearingTabRegistry::normalizeHistorySection($livewire->historySection);
        }

        $legacySubTab = request()->string('smsSubTab')->toString();

        if ($legacySubTab === 'history') {
            return SmsClearingTabRegistry::HISTORY_BATCHES;
        }

        $section = request()->string('historySection')->toString();

        return SmsClearingTabRegistry::normalizeHistorySection($section ?: null);
    }

    public static function resolveListSmsClearingTab(): string
    {
        $livewire = Livewire::current();

        if ($livewire instanceof ListSmsClearing && filled($livewire->activeTab)) {
            $tab = $livewire->activeTab;
        } else {
            $tab = request()->string('tab')->toString();

            if (! filled($tab)) {
                $legacySubTab = request()->string('smsSubTab')->toString();
                $tab = $legacySubTab === 'history'
                    ? SmsClearingTabRegistry::TAB_HISTORY
                    : SmsClearingTabRegistry::TAB_QUEUE;
            }
        }

        return SmsClearingTabRegistry::normalizeTab($tab);
    }

    public static function listUrl(
        string $tab = SmsClearingTabRegistry::TAB_QUEUE,
        ?string $queueFilter = null,
        ?string $historySection = null,
    ): string {
        $parameters = [];

        $normalizedTab = SmsClearingTabRegistry::normalizeTab($tab);

        if ($normalizedTab !== SmsClearingTabRegistry::TAB_QUEUE) {
            $parameters['tab'] = $normalizedTab;
        }

        if ($normalizedTab === SmsClearingTabRegistry::TAB_QUEUE && filled($queueFilter)) {
            $normalizedFilter = SmsClearingTabRegistry::normalizeQueueFilter($queueFilter);

            if ($normalizedFilter !== SmsClearingTabRegistry::FILTER_ALL) {
                $parameters['queueFilter'] = $normalizedFilter;
            }
        }

        if ($normalizedTab === SmsClearingTabRegistry::TAB_HISTORY && filled($historySection)) {
            $normalizedSection = SmsClearingTabRegistry::normalizeHistorySection($historySection);

            if ($normalizedSection !== SmsClearingTabRegistry::HISTORY_BATCHES) {
                $parameters['historySection'] = $normalizedSection;
            }
        }

        return static::getUrl('index', $parameters);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSmsClearing::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withTrashed();
    }
}
