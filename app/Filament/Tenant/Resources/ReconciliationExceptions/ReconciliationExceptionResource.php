<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\ReconciliationExceptions;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Tenant\Resources\ReconciliationExceptions\Pages\ListReconciliationExceptions;
use App\Filament\Tenant\Resources\ReconciliationExceptions\Tables\ReconciliationExceptionsTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\ReconciliationException;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Schema;
use UnitEnum;

class ReconciliationExceptionResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = ReconciliationException::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_FUND_MANAGEMENT;

    protected static ?string $navigationLabel = 'Reconciliation exceptions';

    protected static ?string $modelLabel = 'Reconciliation exception';

    protected static ?string $pluralModelLabel = 'Reconciliation exceptions';

    protected static ?int $navigationSort = TenantNavigation::SORT_RECONCILIATION_EXCEPTIONS;

    protected static bool $shouldRegisterNavigation = true;

    public static function canAccess(): bool
    {
        return auth()->guard('tenant')->check();
    }

    public static function table(Table $table): Table
    {
        return ReconciliationExceptionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReconciliationExceptions::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (!Schema::hasTable('reconciliation_exceptions')) {
            return null;
        }

        try {
            $count = ReconciliationException::query()->open()->count();

            return $count > 0 ? (string) $count : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }
}
