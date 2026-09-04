<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\MemberCashTransferRequests;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Tenant\Resources\MemberCashTransferRequests\Pages\CreateMemberCashTransferRequest;
use App\Filament\Tenant\Resources\MemberCashTransferRequests\Pages\ListMemberCashTransferRequests;
use App\Filament\Tenant\Resources\MemberCashTransferRequests\Schemas\MemberCashTransferRequestForm;
use App\Filament\Tenant\Resources\MemberCashTransferRequests\Tables\MemberCashTransferRequestsTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\MemberCashTransferRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class MemberCashTransferRequestResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = MemberCashTransferRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_FUND_MANAGEMENT;

    protected static ?string $navigationLabel = 'Cash transfers';

    protected static ?string $modelLabel = 'Cash transfer';

    protected static ?string $pluralModelLabel = 'Cash transfers';

    protected static ?int $navigationSort = TenantNavigation::SORT_CASH_TRANSFERS;

    public static function form(Schema $schema): Schema
    {
        return MemberCashTransferRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MemberCashTransferRequestsTable::configure($table);
    }

    public static function getNavigationBadge(): ?string
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('member_cash_transfer_requests')) {
            return null;
        }

        return (string) MemberCashTransferRequest::pending()->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * @param  array<string, array<string, mixed>>  $filters
     */
    public static function listUrl(array $filters = []): string
    {
        $parameters = [];

        if ($filters !== []) {
            $parameters['filters'] = $filters;
        }

        return static::getUrl('index', $parameters, panel: 'tenant');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMemberCashTransferRequests::route('/'),
            'create' => CreateMemberCashTransferRequest::route('/create'),
        ];
    }
}
