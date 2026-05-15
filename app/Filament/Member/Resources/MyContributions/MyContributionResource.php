<?php

namespace App\Filament\Member\Resources\MyContributions;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Member\Resources\MyContributions\Pages\ListMyContributions;
use App\Filament\Member\Resources\MyContributions\Tables\MyContributionsTable;
use App\Models\Tenant\Contribution;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MyContributionResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = Contribution::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?string $navigationLabel = 'My Contributions';

    protected static ?string $modelLabel = 'Contribution';

    protected static ?string $pluralModelLabel = 'My Contributions';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        $member = auth('tenant')->user()?->member;

        return parent::getEloquentQuery()
            ->where('member_id', $member?->id);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return MyContributionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMyContributions::route('/'),
        ];
    }
}
