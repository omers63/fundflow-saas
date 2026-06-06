<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\SmsImportSessions;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Tenant\Resources\SmsImportSessions\Pages\ListSmsImportSessions;
use App\Filament\Tenant\Resources\SmsImportSessions\Pages\ViewSmsImportSession;
use App\Filament\Tenant\Resources\SmsImportSessions\Schemas\SmsImportSessionInfolist;
use App\Filament\Tenant\Resources\SmsImportSessions\Tables\SmsImportSessionsTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\SmsImportSession;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class SmsImportSessionResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = SmsImportSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_ACCOUNTS;

    protected static ?string $navigationLabel = 'SMS import history';

    protected static ?string $modelLabel = 'SMS import session';

    protected static ?string $pluralModelLabel = 'SMS import history';

    protected static ?string $slug = 'sms-import-sessions';

    protected static ?int $navigationSort = 6;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return SmsImportSessionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SmsImportSessionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSmsImportSessions::route('/'),
            'view' => ViewSmsImportSession::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withTrashed();
    }
}
