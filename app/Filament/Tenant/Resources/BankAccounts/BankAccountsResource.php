<?php

namespace App\Filament\Tenant\Resources\BankAccounts;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Tenant\Resources\BankAccounts\Pages\ListBankAccounts;
use App\Filament\Tenant\Resources\BankAccounts\Pages\ViewBankStatement;
use App\Filament\Tenant\Resources\BankAccounts\RelationManagers\BankTransactionsRelationManager;
use App\Filament\Tenant\Resources\BankAccounts\Tables\BankStatementsTable;
use App\Filament\Tenant\Resources\BankAccounts\Tables\BankTransactionsTable;
use App\Models\Tenant\BankStatement;
use BackedEnum;
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

    protected static string|UnitEnum|null $navigationGroup = 'Accounts';

    protected static ?string $navigationLabel = 'Bank Accounts';

    protected static ?string $modelLabel = 'Bank statement';

    protected static ?string $pluralModelLabel = 'Bank accounts';

    protected static ?string $slug = 'bank-accounts';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return match (self::resolveListBankAccountsTab()) {
            'transactions' => BankTransactionsTable::configure(
                $table->pluralModelLabel(__('Transactions')),
            ),
            default => BankStatementsTable::configure(
                $table->pluralModelLabel(__('Statements')),
            ),
        };
    }

    /**
     * Must stay aligned with {@see ListBankAccounts::getTabs()} keys and the `tab` URL query.
     */
    public static function resolveListBankAccountsTab(): string
    {
        $livewire = Livewire::current();

        if ($livewire instanceof ListBankAccounts && filled($livewire->activeTab)) {
            $tab = $livewire->activeTab;
        } else {
            $tab = request()->string('tab')->toString() ?: 'statements';
        }

        return in_array($tab, ['statements', 'transactions'], true) ? $tab : 'statements';
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
