<?php

namespace App\Filament\Tenant\Resources\BankAccounts;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Support\UiLabelIcons;
use App\Filament\Tenant\Resources\BankAccounts\Pages\ListBankAccounts;
use App\Filament\Tenant\Resources\BankAccounts\Pages\ViewBankStatement;
use App\Filament\Tenant\Resources\BankAccounts\RelationManagers\BankTransactionsRelationManager;
use App\Filament\Tenant\Resources\BankAccounts\Tables\BankStatementsTable;
use App\Filament\Tenant\Resources\BankAccounts\Tables\BankTransactionsTable;
use App\Filament\Tenant\Resources\BankAccounts\Tables\MasterBankLedgerTable;
use App\Filament\Tenant\Resources\BankAccounts\Tables\PendingOperationalClearanceTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\BankStatement;
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

    protected static ?string $navigationLabel = 'Bank Accounts';

    protected static ?string $modelLabel = 'Bank statement';

    protected static ?string $pluralModelLabel = 'Bank accounts';

    protected static ?string $slug = 'bank-accounts';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $afterLedgerMutation = Livewire::current() instanceof ListRecords
            ? fn(): mixed => Livewire::current()->resetTable()
            : null;

        return match (self::resolveListBankAccountsTab()) {
            'ledger' => MasterBankLedgerTable::configure(
                $table->pluralModelLabel(UiLabelIcons::tableModelLabel(__('Master bank ledger'))),
                $afterLedgerMutation,
            ),
            'imports', 'transactions' => BankTransactionsTable::configure(
                $table->pluralModelLabel(UiLabelIcons::tableModelLabel(__('Statement lines'))),
            ),
            'clearance' => PendingOperationalClearanceTable::configure(
                $table->pluralModelLabel(UiLabelIcons::tableModelLabel(__('Pending bank match'))),
            ),
            default => BankStatementsTable::configure(
                $table->pluralModelLabel(UiLabelIcons::tableModelLabel(__('Statements'))),
            ),
        };
    }

    /**
     * Must stay aligned with {@see ListBankAccounts::getTabs()} keys and the `tab` URL query.
     */
    public static function resolveListBankAccountsTab(): string
    {
        if (self::resolveChannel() === 'sms') {
            return 'sms';
        }

        $livewire = Livewire::current();

        if ($livewire instanceof ListBankAccounts && filled($livewire->activeTab)) {
            $tab = $livewire->activeTab;
        } else {
            $tab = request()->string('tab')->toString() ?: 'imports';
        }

        return match ($tab) {
            'transactions' => 'imports',
            'ledger', 'statements', 'imports', 'clearance', 'sms' => $tab,
            default => 'imports',
        };
    }

    public static function resolveChannel(): string
    {
        $livewire = Livewire::current();

        if ($livewire instanceof ListBankAccounts) {
            return in_array($livewire->channel, ['bank', 'sms'], true) ? $livewire->channel : 'bank';
        }

        $channel = request()->string('channel')->toString();

        return in_array($channel, ['bank', 'sms'], true) ? $channel : 'bank';
    }

    /**
     * @param  array<string, array<string, mixed>>  $filters
     */
    public static function listUrl(string $tab = 'imports', array $filters = [], string $channel = 'bank', string $smsSubTab = 'transactions'): string
    {
        $parameters = [];

        if ($channel !== 'bank') {
            $parameters['channel'] = $channel;
        }

        if ($channel === 'sms' && $smsSubTab === 'history') {
            $parameters['smsSubTab'] = $smsSubTab;
        }

        if ($channel === 'bank' && $tab !== 'imports') {
            $parameters['tab'] = $tab;
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
