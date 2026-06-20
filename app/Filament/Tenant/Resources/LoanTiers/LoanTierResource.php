<?php

namespace App\Filament\Tenant\Resources\LoanTiers;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Tenant\Clusters\LoansCluster;
use App\Filament\Tenant\Resources\LoanTiers\Pages\CreateLoanTier;
use App\Filament\Tenant\Resources\LoanTiers\Pages\EditLoanTier;
use App\Filament\Tenant\Resources\LoanTiers\Pages\ListLoanTiers;
use App\Filament\Tenant\Resources\LoanTiers\Schemas\LoanTierForm;
use App\Filament\Tenant\Resources\LoanTiers\Tables\LoanTiersTable;
use App\Models\Tenant\LoanTier;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class LoanTierResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = LoanTier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquaresPlus;

    protected static ?string $cluster = LoansCluster::class;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Loan tiers';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return LoanTierForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LoanTiersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLoanTiers::route('/'),
            'create' => CreateLoanTier::route('/create'),
            'edit' => EditLoanTier::route('/{record}/edit'),
        ];
    }
}
