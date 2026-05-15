<?php

namespace App\Filament\Member\Resources\MyAccounts;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Member\Resources\MyAccounts\Pages\ListMyAccounts;
use App\Filament\Member\Resources\MyAccounts\Pages\ViewMyAccount;
use App\Filament\Member\Resources\MyAccounts\RelationManagers\TransactionsRelationManager;
use App\Filament\Member\Resources\MyAccounts\Tables\MyMemberAccountsLoansTable;
use App\Filament\Member\Resources\MyAccounts\Tables\MyMemberAccountsTable;
use App\Models\Tenant\Account;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Livewire;

class MyAccountResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = Account::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'My Accounts';

    protected static ?string $modelLabel = 'Account';

    protected static ?string $pluralModelLabel = 'My Accounts';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        $member = auth('tenant')->user()?->member;

        return parent::getEloquentQuery()
            ->where('member_id', $member?->id)
            ->where('is_master', false);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return match (self::resolveListMyAccountsTab()) {
            'loans' => MyMemberAccountsLoansTable::configure(
                $table->pluralModelLabel(__('Loans')),
            ),
            'all' => MyMemberAccountsTable::configure(
                $table->pluralModelLabel(__('All')),
                showTypeColumn: true,
            ),
            'fund' => MyMemberAccountsTable::configure(
                $table->pluralModelLabel(__('Fund')),
            ),
            default => MyMemberAccountsTable::configure(
                $table->pluralModelLabel(__('Cash')),
            ),
        };
    }

    /**
     * Must stay aligned with {@see ListMyAccounts::getTabs()} keys and the `tab` URL query.
     */
    public static function resolveListMyAccountsTab(): string
    {
        $livewire = Livewire::current();

        if ($livewire instanceof ListMyAccounts && filled($livewire->activeTab)) {
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
            'index' => ListMyAccounts::route('/'),
            'view' => ViewMyAccount::route('/{record}'),
        ];
    }
}
