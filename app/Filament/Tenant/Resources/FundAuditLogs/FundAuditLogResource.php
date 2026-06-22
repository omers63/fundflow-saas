<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\FundAuditLogs;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Tenant\Resources\FundAuditLogs\Pages\ListFundAuditLogs;
use App\Filament\Tenant\Resources\FundAuditLogs\Pages\ViewFundAuditLog;
use App\Filament\Tenant\Resources\FundAuditLogs\Schemas\FundAuditLogInfolist;
use App\Filament\Tenant\Resources\FundAuditLogs\Tables\FundAuditLogsTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\FundAuditLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class FundAuditLogResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = FundAuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_SYSTEM;

    protected static ?string $navigationLabel = 'Audit log';

    protected static ?string $modelLabel = 'Audit log entry';

    protected static ?string $pluralModelLabel = 'Audit log';

    protected static ?int $navigationSort = TenantNavigation::SORT_AUDIT_LOGS;

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return auth()->guard('tenant')->check();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return auth('tenant')->user()?->is_admin === true;
    }

    public static function infolist(Schema $schema): Schema
    {
        return FundAuditLogInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FundAuditLogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFundAuditLogs::route('/'),
            'view' => ViewFundAuditLog::route('/{record}'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }
}
