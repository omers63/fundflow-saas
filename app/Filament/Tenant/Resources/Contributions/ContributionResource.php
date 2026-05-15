<?php

namespace App\Filament\Tenant\Resources\Contributions;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Tenant\Resources\Contributions\Pages\CreateContribution;
use App\Filament\Tenant\Resources\Contributions\Pages\ListContributions;
use App\Filament\Tenant\Resources\Contributions\Schemas\ContributionForm;
use App\Filament\Tenant\Resources\Contributions\Tables\ContributionsTable;
use App\Models\Tenant\Contribution;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ContributionResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = Contribution::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Fund Management';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return ContributionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContributionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContributions::route('/'),
            'create' => CreateContribution::route('/create'),
        ];
    }
}
