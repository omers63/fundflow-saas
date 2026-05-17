<?php

namespace App\Filament\Tenant\Resources\FundPostings;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Tenant\Resources\FundPostings\Pages\ListFundPostings;
use App\Filament\Tenant\Resources\FundPostings\Tables\FundPostingsTable;
use App\Filament\Tenant\Widgets\FundPostingInsightsWidget;
use App\Models\Tenant\FundPosting;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Livewire\Component;
use UnitEnum;

class FundPostingResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = FundPosting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Fund Management';

    protected static ?string $navigationLabel = 'Deposits';

    protected static ?string $modelLabel = 'Deposit';

    protected static ?string $pluralModelLabel = 'Deposits';

    protected static ?int $navigationSort = 4;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return FundPostingsTable::configure($table);
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) FundPosting::pending()->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFundPostings::route('/'),
        ];
    }

    public static function dispatchInsightsRefresh(?Component $livewire): void
    {
        if ($livewire === null) {
            return;
        }

        $targetName = json_encode(
            app('livewire.factory')->resolveComponentName(FundPostingInsightsWidget::class),
            JSON_THROW_ON_ERROR
        );

        $livewire->js(
            'setTimeout(() => window.Livewire.getByName('.$targetName.').forEach(w => w.$refresh()), 0)'
        );
    }
}
