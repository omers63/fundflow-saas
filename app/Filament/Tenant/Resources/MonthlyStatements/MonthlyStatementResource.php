<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\MonthlyStatements;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Tenant\Resources\MonthlyStatements\Pages\ListMonthlyStatements;
use App\Filament\Tenant\Resources\MonthlyStatements\Tables\MonthlyStatementsTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Filament\Tenant\Widgets\MonthlyStatementInsightsWidget;
use App\Models\Tenant\MonthlyStatement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Livewire\Component;
use UnitEnum;

class MonthlyStatementResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = MonthlyStatement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?string $navigationLabel = 'Statements';

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_FUND_MANAGEMENT;

    protected static ?int $navigationSort = TenantNavigation::SORT_STATEMENTS;

    public static function table(Table $table): Table
    {
        return MonthlyStatementsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMonthlyStatements::route('/'),
        ];
    }

    public static function dispatchInsightsRefresh(?Component $livewire): void
    {
        if ($livewire === null) {
            return;
        }

        $targetName = json_encode(
            app('livewire.factory')->resolveComponentName(MonthlyStatementInsightsWidget::class),
            JSON_THROW_ON_ERROR
        );

        $livewire->js(
            'setTimeout(() => window.Livewire.getByName('.$targetName.').forEach(w => w.$refresh()), 0)'
        );
    }
}
