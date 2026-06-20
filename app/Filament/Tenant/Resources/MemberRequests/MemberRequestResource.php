<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\MemberRequests;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Tenant\Concerns\HidesFromTenantSidebar;
use App\Filament\Tenant\Resources\MemberRequests\Pages\ListMemberRequests;
use App\Filament\Tenant\Resources\MemberRequests\Tables\MemberRequestsTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\MemberRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MemberRequestResource extends Resource
{
    use HidesFromTenantSidebar;
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = MemberRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|\UnitEnum|null $navigationGroup = TenantNavigation::GROUP_FUND_MANAGEMENT;

    protected static ?string $navigationLabel = 'Requests';

    protected static ?string $modelLabel = 'Member request';

    protected static ?string $pluralModelLabel = 'Member requests';

    protected static ?int $navigationSort = TenantNavigation::SORT_MEMBER_REQUESTS;

    public static function canAccess(): bool
    {
        return (bool) auth('tenant')->user()?->is_admin;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    public static function table(Table $table): Table
    {
        return MemberRequestsTable::configure($table);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = MemberRequest::query()
            ->where('status', MemberRequest::STATUS_PENDING)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMemberRequests::route('/'),
        ];
    }
}
