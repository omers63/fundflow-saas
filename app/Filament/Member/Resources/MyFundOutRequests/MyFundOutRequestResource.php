<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyFundOutRequests;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Member\Resources\MyFundOutRequests\Pages\ListMyFundOutRequests;
use App\Filament\Member\Resources\MyFundOutRequests\Tables\MyFundOutRequestsTable;
use App\Filament\Member\Support\MemberNavigation;
use App\Models\Tenant\FundOutRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MyFundOutRequestResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = FundOutRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowRightCircle;

    protected static ?string $navigationLabel = 'Fund out';

    protected static ?string $modelLabel = 'Fund out';

    protected static ?string $pluralModelLabel = 'Fund outs';

    protected static string|\UnitEnum|null $navigationGroup = MemberNavigation::GROUP_SELF_SERVICE;

    protected static ?int $navigationSort = MemberNavigation::SORT_FUND_OUTS;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $member = auth('tenant')->user()?->member;

        return parent::getEloquentQuery()
            ->where('member_id', $member?->id);
    }

    public static function table(Table $table): Table
    {
        return MyFundOutRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMyFundOutRequests::route('/'),
        ];
    }
}
