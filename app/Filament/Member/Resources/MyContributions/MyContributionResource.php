<?php

namespace App\Filament\Member\Resources\MyContributions;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Member\Resources\MyContributions\Pages\ListMyContributions;
use App\Filament\Member\Resources\MyContributions\Tables\MyContributionsTable;
use App\Filament\Member\Support\MemberNavigation;
use App\Models\Tenant\Contribution;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

class MyContributionResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = Contribution::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?string $navigationLabel = 'Contributions';

    protected static ?string $modelLabel = 'Contribution';

    protected static ?string $pluralModelLabel = 'My Contributions';

    protected static string|\UnitEnum|null $navigationGroup = MemberNavigation::GROUP_HISTORY;

    protected static ?int $navigationSort = MemberNavigation::SORT_CONTRIBUTIONS;

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

    /**
     * @param  array<string, array<string, mixed>>  $filters
     */
    public static function listUrl(array $filters = []): string
    {
        $parameters = [];

        if ($filters !== []) {
            $parameters['filters'] = $filters;
        }

        return static::getUrl('index', $parameters);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMyContributions::route('/'),
        ];
    }

    public static function dispatchInsightsRefresh(?Component $livewire): void
    {
        if ($livewire === null) {
            return;
        }

        // Stats render in the list page header (not a nested Livewire widget), so refresh the page.
        $livewire->js('setTimeout(() => $wire.$refresh(), 0)');
    }
}
