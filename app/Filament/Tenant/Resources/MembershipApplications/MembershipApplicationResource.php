<?php

namespace App\Filament\Tenant\Resources\MembershipApplications;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Tenant\Resources\MembershipApplications\Pages\CreateMembershipApplication;
use App\Filament\Tenant\Resources\MembershipApplications\Pages\EditMembershipApplication;
use App\Filament\Tenant\Resources\MembershipApplications\Pages\ListMembershipApplications;
use App\Filament\Tenant\Resources\MembershipApplications\Schemas\MembershipApplicationForm;
use App\Filament\Tenant\Resources\MembershipApplications\Tables\MembershipApplicationsTable;
use App\Models\Tenant\MembershipApplication;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MembershipApplicationResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = MembershipApplication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|\UnitEnum|null $navigationGroup = 'Fund Management';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Applications';

    public static function canCreate(): bool
    {
        return (bool) auth('tenant')->user()?->is_admin;
    }

    public static function form(Schema $schema): Schema
    {
        return MembershipApplicationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MembershipApplicationsTable::configure($table);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = MembershipApplication::pending()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembershipApplications::route('/'),
            'create' => CreateMembershipApplication::route('/create'),
            'edit' => EditMembershipApplication::route('/{record}/edit'),
        ];
    }
}
