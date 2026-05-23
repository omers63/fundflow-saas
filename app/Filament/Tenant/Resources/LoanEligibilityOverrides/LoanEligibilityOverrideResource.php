<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\LoanEligibilityOverrides;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Tenant\Resources\LoanEligibilityOverrides\Pages\ListLoanEligibilityOverrides;
use App\Filament\Tenant\Resources\LoanEligibilityOverrides\Tables\LoanEligibilityOverridesTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\LoanEligibilityOverride;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class LoanEligibilityOverrideResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = LoanEligibilityOverride::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_SYSTEM;

    protected static ?string $navigationLabel = 'Loan overrides';

    protected static ?string $modelLabel = 'Loan eligibility override';

    protected static ?int $navigationSort = TenantNavigation::SORT_LOAN_OVERRIDES;

    protected static bool $shouldRegisterNavigation = true;

    public static function canAccess(): bool
    {
        return auth()->guard('tenant')->check();
    }

    public static function table(Table $table): Table
    {
        return LoanEligibilityOverridesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLoanEligibilityOverrides::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return true;
    }
}
