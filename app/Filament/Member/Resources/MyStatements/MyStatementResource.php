<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyStatements;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Member\Resources\MyStatements\Pages\ListMyStatements;
use App\Filament\Member\Resources\MyStatements\Tables\MyStatementsTable;
use App\Models\Tenant\MonthlyStatement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MyStatementResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = MonthlyStatement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?string $navigationLabel = 'My statements';

    protected static ?int $navigationSort = 50;

    public static function getEloquentQuery(): Builder
    {
        $memberId = auth('tenant')->user()?->member?->id;

        return parent::getEloquentQuery()->where('member_id', $memberId);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return MyStatementsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMyStatements::route('/'),
        ];
    }
}
