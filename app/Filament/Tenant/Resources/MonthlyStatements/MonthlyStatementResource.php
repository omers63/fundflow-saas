<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\MonthlyStatements;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Tenant\Concerns\HidesFromTenantSidebar;
use App\Filament\Tenant\Resources\MonthlyStatements\Pages\CreateMonthlyStatement;
use App\Filament\Tenant\Resources\MonthlyStatements\Pages\EditMonthlyStatement;
use App\Filament\Tenant\Resources\MonthlyStatements\Pages\ListMonthlyStatements;
use App\Filament\Tenant\Resources\MonthlyStatements\Schemas\MonthlyStatementForm;
use App\Filament\Tenant\Resources\MonthlyStatements\Tables\MonthlyStatementsTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Filament\Tenant\Widgets\MonthlyStatementInsightsWidget;
use App\Models\Tenant\MonthlyStatement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use UnitEnum;

class MonthlyStatementResource extends Resource
{
    use HidesFromTenantSidebar;
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = MonthlyStatement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?string $navigationLabel = 'Statements';

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_FUND_MANAGEMENT;

    protected static ?int $navigationSort = TenantNavigation::SORT_STATEMENTS;

    public static function getNavigationBadge(): ?string
    {
        $count = MonthlyStatement::query()
            ->whereNull('notified_at')
            ->where('generated_at', '>=', now()->subDays(7))
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function canAccess(): bool
    {
        return auth('tenant')->user()?->is_admin === true;
    }

    public static function form(Schema $schema): Schema
    {
        return MonthlyStatementForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MonthlyStatementsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMonthlyStatements::route('/'),
            'create' => CreateMonthlyStatement::route('/create'),
            'edit' => EditMonthlyStatement::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withTrashed();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withTrashed();
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
