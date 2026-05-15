<?php

namespace App\Filament\Tenant\Resources\Accounts;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Tenant\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Tenant\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Tenant\Resources\Accounts\RelationManagers\TransactionsRelationManager;
use App\Filament\Tenant\Resources\Accounts\Tables\MemberAccountsLoansTable;
use App\Filament\Tenant\Resources\Accounts\Tables\MemberAccountsTable;
use App\Models\Tenant\Account;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Livewire;
use UnitEnum;

class AccountResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = Account::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Accounts';

    protected static ?string $navigationLabel = 'Member Accounts';

    protected static ?string $modelLabel = 'Member Account';

    protected static ?string $pluralModelLabel = 'Member Accounts';

    protected static ?string $slug = 'member-accounts';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('is_master', false);
    }

    public static function table(Table $table): Table
    {
        return match (self::resolveListMemberAccountsTab()) {
            'loans' => MemberAccountsLoansTable::configure(
                $table->pluralModelLabel(__('Loans')),
            ),
            'all' => MemberAccountsTable::configure(
                $table->pluralModelLabel(__('All accounts')),
                showTypeColumn: true,
            ),
            'fund' => MemberAccountsTable::configure(
                $table->pluralModelLabel(__('Fund accounts')),
            ),
            default => MemberAccountsTable::configure(
                $table->pluralModelLabel(__('Cash accounts')),
            ),
        };
    }

    /**
     * Must stay aligned with {@see ListAccounts::getTabs()} keys and the `tab` URL query.
     */
    public static function resolveListMemberAccountsTab(): string
    {
        $livewire = Livewire::current();

        if ($livewire instanceof ListAccounts && filled($livewire->activeTab)) {
            $tab = $livewire->activeTab;
        } else {
            $tab = request()->string('tab')->toString() ?: 'cash';
        }

        return in_array($tab, ['cash', 'fund', 'loans', 'all'], true) ? $tab : 'cash';
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
            'index' => ListAccounts::route('/'),
            'view' => ViewAccount::route('/{record}'),
        ];
    }
}
