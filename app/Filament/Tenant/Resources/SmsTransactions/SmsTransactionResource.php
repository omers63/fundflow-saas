<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\SmsTransactions;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Tenant\Resources\SmsTransactions\Pages\ListSmsTransactions;
use App\Filament\Tenant\Resources\SmsTransactions\Pages\ViewSmsTransaction;
use App\Filament\Tenant\Resources\SmsTransactions\Schemas\SmsTransactionInfolist;
use App\Filament\Tenant\Resources\SmsTransactions\Tables\SmsTransactionsTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\SmsTransaction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use UnitEnum;

class SmsTransactionResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = SmsTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_ACCOUNTS;

    protected static ?string $navigationLabel = 'SMS transactions';

    protected static ?string $modelLabel = 'SMS transaction';

    protected static ?string $pluralModelLabel = 'SMS transactions';

    protected static ?string $slug = 'sms-transactions';

    protected static ?int $navigationSort = 5;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! DatabaseSchema::hasTable('sms_transactions')) {
            return null;
        }

        $count = SmsTransaction::query()->where('is_duplicate', true)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return SmsTransactionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SmsTransactionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSmsTransactions::route('/'),
            'view' => ViewSmsTransaction::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withTrashed();
    }
}
