<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\Transactions;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Support\UiLabelIcons;
use App\Filament\Tenant\Resources\Transactions\Pages\ListTransactions;
use App\Filament\Tenant\Resources\Transactions\Tables\TransactionsTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\Transaction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class TransactionResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = Transaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_ACCOUNTS;

    protected static ?string $navigationLabel = 'Transactions';

    protected static ?string $modelLabel = 'Transaction';

    protected static ?string $pluralModelLabel = 'Transactions';

    protected static ?string $slug = 'transactions';

    protected static ?int $navigationSort = TenantNavigation::SORT_TRANSACTIONS;

    protected static ?string $recordTitleAttribute = 'description';

    public static function canAccess(): bool
    {
        return auth()->guard('tenant')->check();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['account', 'member', 'reference']);
    }

    public static function table(Table $table): Table
    {
        return TransactionsTable::configure(
            $table->pluralModelLabel(UiLabelIcons::tableModelLabel(__('Transactions'))),
        );
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTransactions::route('/'),
        ];
    }
}
