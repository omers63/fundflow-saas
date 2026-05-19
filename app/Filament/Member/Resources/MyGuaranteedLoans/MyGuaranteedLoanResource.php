<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyGuaranteedLoans;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Member\Resources\MyGuaranteedLoans\Pages\ListMyGuaranteedLoans;
use App\Filament\Member\Resources\MyGuaranteedLoans\Pages\ViewMyGuaranteedLoan;
use App\Filament\Member\Resources\MyGuaranteedLoans\Tables\MyGuaranteedLoansTable;
use App\Filament\Member\Support\MemberNavigation;
use App\Models\Tenant\Loan;
use App\Support\Tenant\CurrentMember;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MyGuaranteedLoanResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = Loan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Guaranteed loans';

    protected static ?string $modelLabel = 'Guaranteed loan';

    protected static ?string $pluralModelLabel = 'Guaranteed loans';

    protected static string|\UnitEnum|null $navigationGroup = MemberNavigation::GROUP_LOANS;

    protected static ?int $navigationSort = MemberNavigation::SORT_GUARANTEED_LOANS;

    public static function getEloquentQuery(): Builder
    {
        $memberId = CurrentMember::get()?->id;

        return parent::getEloquentQuery()
            ->where('guarantor_member_id', $memberId)
            ->with(['member', 'installments']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return MyGuaranteedLoansTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMyGuaranteedLoans::route('/'),
            'view' => ViewMyGuaranteedLoan::route('/{record}'),
        ];
    }
}
