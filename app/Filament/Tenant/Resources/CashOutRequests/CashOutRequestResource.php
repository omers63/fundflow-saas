<?php

namespace App\Filament\Tenant\Resources\CashOutRequests;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Tenant\Resources\CashOutRequests\Pages\ListCashOutRequests;
use App\Filament\Tenant\Resources\CashOutRequests\Tables\CashOutRequestsTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\CashOutRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CashOutRequestResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = CashOutRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_FUND_MANAGEMENT;

    protected static ?string $navigationLabel = 'Cash outs';

    protected static ?string $modelLabel = 'Cash out';

    protected static ?string $pluralModelLabel = 'Cash outs';

    protected static ?int $navigationSort = TenantNavigation::SORT_CASH_OUTS;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return CashOutRequestsTable::configure($table);
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) CashOutRequest::pending()->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCashOutRequests::route('/'),
        ];
    }
}
