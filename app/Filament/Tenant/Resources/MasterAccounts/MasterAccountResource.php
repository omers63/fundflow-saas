<?php

namespace App\Filament\Tenant\Resources\MasterAccounts;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Tenant\Resources\MasterAccounts\Pages\ListMasterAccounts;
use App\Filament\Tenant\Resources\MasterAccounts\Pages\ViewMasterAccount;
use App\Filament\Tenant\Resources\MasterAccounts\RelationManagers\TransactionsRelationManager;
use App\Filament\Tenant\Resources\MasterAccounts\Tables\MasterAccountsTable;
use App\Models\Tenant\Account;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Livewire;
use UnitEnum;

class MasterAccountResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = Account::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Accounts';

    protected static ?string $navigationLabel = 'Master Accounts';

    protected static ?string $modelLabel = 'Master Account';

    protected static ?string $pluralModelLabel = 'Master Accounts';

    protected static ?string $slug = 'master-accounts';

    protected static ?int $navigationSort = 1;

    /**
     * Master account tab keys, aligned with seeded master account types.
     *
     * @return list<string>
     */
    public static function tabTypes(): array
    {
        return ['cash', 'fund', 'bank', 'expense', 'fees', 'invest'];
    }

    /**
     * @return list<string>
     */
    public static function tabKeys(): array
    {
        return [...self::tabTypes(), 'all'];
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
        $tab = self::resolveListMasterAccountsTab();

        return MasterAccountsTable::configure(
            $table->pluralModelLabel(self::tabLabel($tab)),
            showTypeColumn: $tab === 'all',
        );
    }

    /**
     * Must stay aligned with {@see ListMasterAccounts::getTabs()} keys and the `tab` URL query.
     */
    public static function resolveListMasterAccountsTab(): string
    {
        $livewire = Livewire::current();

        if ($livewire instanceof ListMasterAccounts && filled($livewire->activeTab)) {
            $tab = $livewire->activeTab;
        } else {
            $tab = request()->string('tab')->toString() ?: 'cash';
        }

        return in_array($tab, self::tabKeys(), true) ? $tab : 'cash';
    }

    public static function getRelations(): array
    {
        return [
            TransactionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMasterAccounts::route('/'),
            'view' => ViewMasterAccount::route('/{record}'),
        ];
    }
}
