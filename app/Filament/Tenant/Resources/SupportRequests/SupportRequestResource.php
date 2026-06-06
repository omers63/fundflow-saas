<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\SupportRequests;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Tenant\Resources\SupportRequests\Pages\ListSupportRequests;
use App\Filament\Tenant\Resources\SupportRequests\Tables\SupportRequestsTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\SupportRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SupportRequestResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = SupportRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|\UnitEnum|null $navigationGroup = TenantNavigation::GROUP_FUND_MANAGEMENT;

    protected static ?string $navigationLabel = 'Support';

    protected static ?string $modelLabel = 'Support request';

    protected static ?string $pluralModelLabel = 'Support requests';

    protected static ?int $navigationSort = TenantNavigation::SORT_SUPPORT_REQUESTS;

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
        return SupportRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupportRequests::route('/'),
        ];
    }
}
