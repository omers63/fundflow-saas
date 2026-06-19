<?php

namespace App\Filament\Member\Resources\MyCashOutRequests;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Member\Resources\MyCashOutRequests\Pages\CreateMyCashOutRequest;
use App\Filament\Member\Resources\MyCashOutRequests\Pages\ListMyCashOutRequests;
use App\Filament\Member\Resources\MyCashOutRequests\Schemas\MyCashOutRequestForm;
use App\Filament\Member\Resources\MyCashOutRequests\Tables\MyCashOutRequestsTable;
use App\Filament\Member\Support\MemberNavigation;
use App\Models\Tenant\CashOutRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MyCashOutRequestResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = CashOutRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $navigationLabel = 'Cash out';

    protected static ?string $modelLabel = 'Cash out';

    protected static ?string $pluralModelLabel = 'Cash outs';

    protected static string|\UnitEnum|null $navigationGroup = MemberNavigation::GROUP_SELF_SERVICE;

    protected static ?int $navigationSort = MemberNavigation::SORT_CASH_OUTS;

    public static function getEloquentQuery(): Builder
    {
        $member = auth('tenant')->user()?->member;

        return parent::getEloquentQuery()
            ->where('member_id', $member?->id);
    }

    public static function form(Schema $schema): Schema
    {
        return MyCashOutRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MyCashOutRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMyCashOutRequests::route('/'),
            'create' => CreateMyCashOutRequest::route('/create'),
        ];
    }
}
