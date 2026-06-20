<?php

namespace App\Filament\Tenant\Resources\Accounts;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Support\UiLabelIcons;
use App\Filament\Tenant\Concerns\HidesFromTenantSidebar;
use App\Filament\Tenant\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Tenant\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Tenant\Resources\Accounts\RelationManagers\TransactionsRelationManager;
use App\Filament\Tenant\Resources\Accounts\Tables\MemberAccountsLoansTable;
use App\Filament\Tenant\Resources\Accounts\Tables\MemberAccountsTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Filament\Tenant\Widgets\MemberAccountsInsightsWidget;
use App\Models\Tenant\Account;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\Livewire;
use UnitEnum;

class AccountResource extends Resource
{
    use HidesFromTenantSidebar;
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = Account::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_ACCOUNTS;

    protected static ?string $navigationLabel = 'Member Accounts';

    protected static ?string $modelLabel = 'Member Account';

    protected static ?string $pluralModelLabel = 'Member Accounts';

    protected static ?string $slug = 'member-accounts';

    protected static ?int $navigationSort = 3;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('is_master', false);
    }

    /**
     * @return list<string>
     */
    public static function tabKeys(): array
    {
        return ['all', 'cash', 'fund', 'loans'];
    }

    public static function tabLabel(string $tab): string
    {
        return match ($tab) {
            'all' => __('All'),
            'cash' => __('Cash'),
            'fund' => __('Fund'),
            'loans' => __('Loans'),
            default => ucfirst($tab),
        };
    }

    public static function table(Table $table): Table
    {
        return match (self::resolveListMemberAccountsTab()) {
            'loans' => MemberAccountsLoansTable::configure(
                $table->pluralModelLabel(UiLabelIcons::tableModelLabel(__('Loans'))),
            ),
            'all' => MemberAccountsTable::configure(
                $table->pluralModelLabel(UiLabelIcons::tableModelLabel(__('All accounts'))),
                showTypeColumn: true,
            ),
            'fund' => MemberAccountsTable::configure(
                $table->pluralModelLabel(UiLabelIcons::tableModelLabel(__('Fund accounts'))),
            ),
            default => MemberAccountsTable::configure(
                $table->pluralModelLabel(UiLabelIcons::tableModelLabel(__('Cash accounts'))),
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
            $tab = request()->string('tab')->toString() ?: 'all';
        }

        return in_array($tab, self::tabKeys(), true) ? $tab : 'all';
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

    public static function dispatchInsightsRefresh(?Component $livewire): void
    {
        if ($livewire === null) {
            return;
        }

        $targetName = json_encode(
            app('livewire.factory')->resolveComponentName(MemberAccountsInsightsWidget::class),
            JSON_THROW_ON_ERROR
        );

        $livewire->js(
            'setTimeout(() => window.Livewire.getByName('.$targetName.').forEach(w => w.$refresh()), 0)'
        );
    }
}
