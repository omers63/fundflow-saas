<?php

namespace App\Filament\Tenant\Resources\Loans;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Tenant\Resources\Loans\Pages\CreateLoan;
use App\Filament\Tenant\Resources\Loans\Pages\EditLoan;
use App\Filament\Tenant\Resources\Loans\Pages\ListLoans;
use App\Filament\Tenant\Resources\Loans\Schemas\LoanForm;
use App\Filament\Tenant\Resources\Loans\Tables\LoansTable;
use App\Models\Tenant\Loan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class LoanResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = Loan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|\UnitEnum|null $navigationGroup = 'Loans';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return LoanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LoansTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLoans::route('/'),
            'create' => CreateLoan::route('/create'),
            'edit' => EditLoan::route('/{record}/edit'),
        ];
    }
}
