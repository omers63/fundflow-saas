<?php

namespace App\Filament\Tenant\Resources\MasterAccounts;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Support\UiLabelIcons;
use App\Filament\Tenant\Concerns\HidesFromTenantSidebar;
use App\Filament\Tenant\Resources\MasterAccounts\Pages\ListMasterAccounts;
use App\Filament\Tenant\Resources\MasterAccounts\Pages\ViewMasterAccount;
use App\Filament\Tenant\Resources\MasterAccounts\RelationManagers\BankLinesAwaitingPostingRelationManager;
use App\Filament\Tenant\Resources\MasterAccounts\RelationManagers\PendingOperationalClearanceRelationManager;
use App\Filament\Tenant\Resources\MasterAccounts\RelationManagers\TransactionsRelationManager;
use App\Filament\Tenant\Resources\MasterAccounts\Tables\MasterAccountsTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\Account;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class MasterAccountResource extends Resource
{
    use HidesFromTenantSidebar;
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = Account::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_ACCOUNTS;

    protected static ?string $navigationLabel = 'Master Accounts';

    protected static ?string $modelLabel = 'Master Account';

    protected static ?string $pluralModelLabel = 'Master Accounts';

    protected static ?string $slug = 'master-accounts';

    protected static ?int $navigationSort = 2;

    /**
     * Master account tab keys, aligned with seeded master account types.
     *
     * @return list<string>
     */
    public static function tabTypes(): array
    {
        return ['cash', 'fund', 'bank', 'expense', 'fees', 'invest', 'suspense'];
    }

    /**
     * @return list<string>
     */
    public static function tabKeys(): array
    {
        return ['all', ...self::tabTypes()];
    }

    public static function tabLabel(string $tab): string
    {
        return match ($tab) {
            'cash' => __('Cash'),
            'fund' => __('Fund'),
            'bank' => __('Bank'),
            'expense' => __('Expense'),
            'fees' => __('Fees'),
            'invest' => __('Invest'),
            'suspense' => __('Suspense'),
            'all' => __('All'),
            default => ucfirst($tab),
        };
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('is_master', true);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return MasterAccountsTable::configure(
            $table->pluralModelLabel(UiLabelIcons::tableModelLabel(self::tabLabel('all'))),
        );
    }

    public static function resolveListMasterAccountsTab(): string
    {
        return 'all';
    }

    public static function getRelations(): array
    {
        return [
            BankLinesAwaitingPostingRelationManager::class,
            PendingOperationalClearanceRelationManager::class,
            TransactionsRelationManager::class,
        ];
    }

    public static function listUrl(string $tab = 'all'): string
    {
        $parameters = [];

        if ($tab !== 'all') {
            $parameters['tab'] = $tab;
        }

        return static::getUrl('index', $parameters);
    }

    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        return $record instanceof Account ? $record->displayLabel() : parent::getRecordTitle($record);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMasterAccounts::route('/'),
            'view' => ViewMasterAccount::route('/{record}'),
        ];
    }
}
