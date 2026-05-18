<?php

namespace App\Filament\Member\Resources\MyLoans;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Member\Resources\MyLoans\Pages\ListMyLoans;
use App\Filament\Member\Resources\MyLoans\Pages\ViewMyLoan;
use App\Filament\Member\Resources\MyLoans\RelationManagers\InstallmentsRelationManager;
use App\Filament\Member\Resources\MyLoans\Tables\MyLoansTable;
use App\Models\Tenant\Loan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MyLoanResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = Loan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'My Loans';

    protected static ?string $modelLabel = 'Loan';

    protected static ?string $pluralModelLabel = 'My Loans';

    protected static ?int $navigationSort = 40;

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
        return MyLoansTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            InstallmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMyLoans::route('/'),
            'view' => ViewMyLoan::route('/{record}'),
        ];
    }
}
