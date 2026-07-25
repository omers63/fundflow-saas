<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyCashTransfers;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Member\Resources\MyCashTransfers\Pages\CreateMyCashTransfer;
use App\Filament\Member\Resources\MyCashTransfers\Pages\ListMyCashTransfers;
use App\Filament\Member\Resources\MyCashTransfers\Schemas\MyCashTransferForm;
use App\Filament\Member\Resources\MyCashTransfers\Tables\MyCashTransfersTable;
use App\Filament\Member\Support\MemberNavigation;
use App\Models\Tenant\MemberCashTransferRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MyCashTransferResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = MemberCashTransferRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $navigationLabel = 'Cash transfer';

    protected static ?string $modelLabel = 'Cash transfer';

    protected static ?string $pluralModelLabel = 'Cash transfers';

    protected static string|\UnitEnum|null $navigationGroup = MemberNavigation::GROUP_SELF_SERVICE;

    protected static ?int $navigationSort = MemberNavigation::SORT_CASH_TRANSFERS;

    public static function getEloquentQuery(): Builder
    {
        $member = auth('tenant')->user()?->member;

        return parent::getEloquentQuery()
            ->where(function (Builder $query) use ($member): void {
                $query->where('from_member_id', $member?->id)
                    ->orWhere('to_member_id', $member?->id);
            });
    }

    public static function form(Schema $schema): Schema
    {
        return MyCashTransferForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MyCashTransfersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMyCashTransfers::route('/'),
            'create' => CreateMyCashTransfer::route('/create'),
        ];
    }
}
