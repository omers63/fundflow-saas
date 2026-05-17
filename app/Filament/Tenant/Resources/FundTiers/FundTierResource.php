<?php

namespace App\Filament\Tenant\Resources\FundTiers;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Tenant\Clusters\LoansCluster;
use App\Filament\Tenant\Resources\FundTiers\Pages\CreateFundTier;
use App\Filament\Tenant\Resources\FundTiers\Pages\EditFundTier;
use App\Filament\Tenant\Resources\FundTiers\Pages\ListFundTiers;
use App\Filament\Tenant\Resources\FundTiers\Schemas\FundTierForm;
use App\Filament\Tenant\Resources\FundTiers\Tables\FundTiersTable;
use App\Models\Tenant\FundTier;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class FundTierResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = FundTier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static ?string $cluster = LoansCluster::class;

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Fund tiers';

    public static function form(Schema $schema): Schema
    {
        return FundTierForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FundTiersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFundTiers::route('/'),
            'create' => CreateFundTier::route('/create'),
            'edit' => EditFundTier::route('/{record}/edit'),
        ];
    }
}
