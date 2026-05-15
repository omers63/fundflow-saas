<?php

namespace App\Filament\Tenant\Resources\Members;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Tenant\Resources\Members\Pages\CreateMember;
use App\Filament\Tenant\Resources\Members\Pages\EditMember;
use App\Filament\Tenant\Resources\Members\Pages\ListMembers;
use App\Filament\Tenant\Resources\Members\Pages\ViewMember;
use App\Filament\Tenant\Resources\Members\RelationManagers\AccountsRelationManager;
use App\Filament\Tenant\Resources\Members\RelationManagers\ContributionsRelationManager;
use App\Filament\Tenant\Resources\Members\RelationManagers\DependentsRelationManager;
use App\Filament\Tenant\Resources\Members\RelationManagers\RepaymentsRelationManager;
use App\Filament\Tenant\Resources\Members\Schemas\MemberForm;
use App\Filament\Tenant\Resources\Members\Tables\MembersTable;
use App\Models\Tenant\Member;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class MemberResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = Member::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Fund Management';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return MemberForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MembersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            AccountsRelationManager::class,
            ContributionsRelationManager::class,
            RepaymentsRelationManager::class,
            DependentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembers::route('/'),
            'create' => CreateMember::route('/create'),
            'view' => ViewMember::route('/{record}'),
            'edit' => EditMember::route('/{record}/edit'),
        ];
    }
}
