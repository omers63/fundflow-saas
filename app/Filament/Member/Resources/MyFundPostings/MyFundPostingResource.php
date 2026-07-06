<?php

namespace App\Filament\Member\Resources\MyFundPostings;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Member\Resources\MyFundPostings\Pages\CreateMyFundPosting;
use App\Filament\Member\Resources\MyFundPostings\Pages\ListMyFundPostings;
use App\Filament\Member\Resources\MyFundPostings\Schemas\MyFundPostingForm;
use App\Filament\Member\Resources\MyFundPostings\Tables\MyFundPostingsTable;
use App\Filament\Member\Support\MemberNavigation;
use App\Models\Tenant\FundPosting;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MyFundPostingResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = FundPosting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Deposits';

    protected static ?string $modelLabel = 'Deposit';

    protected static ?string $pluralModelLabel = 'Deposits';

    protected static string|\UnitEnum|null $navigationGroup = MemberNavigation::GROUP_SELF_SERVICE;

    protected static ?int $navigationSort = MemberNavigation::SORT_DEPOSITS;

    public static function getEloquentQuery(): Builder
    {
        $member = auth('tenant')->user()?->member;

        return parent::getEloquentQuery()
            ->where('member_id', $member?->id);
    }

    public static function form(Schema $schema): Schema
    {
        return MyFundPostingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MyFundPostingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMyFundPostings::route('/'),
            'create' => CreateMyFundPosting::route('/create'),
        ];
    }
}
