<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\NotificationLogs;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Tenant\Resources\NotificationLogs\Pages\ListNotificationLogs;
use App\Filament\Tenant\Resources\NotificationLogs\Pages\ViewNotificationLog;
use App\Filament\Tenant\Resources\NotificationLogs\Schemas\NotificationLogInfolist;
use App\Filament\Tenant\Resources\NotificationLogs\Tables\NotificationLogsTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\NotificationLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class NotificationLogResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = NotificationLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_SYSTEM;

    protected static ?string $navigationLabel = 'Notification logs';

    protected static ?string $modelLabel = 'Notification log';

    protected static ?string $pluralModelLabel = 'Notification logs';

    protected static ?int $navigationSort = TenantNavigation::SORT_NOTIFICATION_LOGS;

    public static function getNavigationBadge(): ?string
    {
        $failed = NotificationLog::query()->where('status', 'failed')->count();

        return $failed > 0 ? (string) $failed : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function canAccess(): bool
    {
        return auth('tenant')->user()?->is_admin === true;
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
        return NotificationLogInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NotificationLogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotificationLogs::route('/'),
            'view' => ViewNotificationLog::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withTrashed();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }
}
