<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\ReconciliationExceptions;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Tenant\Resources\ReconciliationExceptions\Pages\ListReconciliationExceptions;
use App\Filament\Tenant\Resources\ReconciliationExceptions\Tables\ReconciliationExceptionsTable;
use App\Models\Tenant\ReconciliationException;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ReconciliationExceptionResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = ReconciliationException::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?string $modelLabel = 'Reconciliation exception';

    protected static ?string $pluralModelLabel = 'Reconciliation exceptions';

    protected static bool $shouldRegisterNavigation = false;

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
}
