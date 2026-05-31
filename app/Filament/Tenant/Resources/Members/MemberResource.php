<?php

namespace App\Filament\Tenant\Resources\Members;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Tenant\Resources\Members\Pages\CreateMember;
use App\Filament\Tenant\Resources\Members\Pages\EditMember;
use App\Filament\Tenant\Resources\Members\Pages\ListMembers;
use App\Filament\Tenant\Resources\Members\RelationManagers\AccountsRelationManager;
use App\Filament\Tenant\Resources\Members\RelationManagers\ContributionsRelationManager;
use App\Filament\Tenant\Resources\Members\RelationManagers\DependentsRelationManager;
use App\Filament\Tenant\Resources\Members\RelationManagers\LoansRelationManager;
use App\Filament\Tenant\Resources\Members\RelationManagers\MessagesRelationManager;
use App\Filament\Tenant\Resources\Members\RelationManagers\MigrationStubsRelationManager;
use App\Filament\Tenant\Resources\Members\RelationManagers\RepaymentsRelationManager;
use App\Filament\Tenant\Resources\Members\Schemas\MemberForm;
use App\Filament\Tenant\Resources\Members\Tables\MembersTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Filament\Tenant\Widgets\MemberDetailInsightsWidget;
use App\Filament\Tenant\Widgets\MemberInsightsWidget;
use App\Models\Tenant\Member;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Livewire\Component;
use UnitEnum;

class MemberResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = Member::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_FUND_MANAGEMENT;

    protected static ?int $navigationSort = TenantNavigation::SORT_MEMBERS;

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
            LoansRelationManager::class,
            DependentsRelationManager::class,
            MessagesRelationManager::class,
            MigrationStubsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembers::route('/'),
            'create' => CreateMember::route('/create'),
            'edit' => EditMember::route('/{record}/edit'),
        ];
    }

    /**
     * Edit URL with a specific relation manager tab active (uses Filament's `relation` query param).
     */
    public static function editUrlWithRelationManager(Member|int|string $record, string $relationManagerClass): string
    {
        $recordKey = $record instanceof Member ? $record->getKey() : $record;
        $parameters = ['record' => $recordKey];

        $managerIndex = array_search($relationManagerClass, static::getRelations(), true);

        if ($managerIndex !== false) {
            $parameters['relation'] = (string) $managerIndex;
        }

        return static::getUrl('edit', $parameters);
    }

    public static function dispatchInsightsRefresh(?Component $livewire): void
    {
        if ($livewire === null) {
            return;
        }

        $targetName = json_encode(
            app('livewire.factory')->resolveComponentName(MemberInsightsWidget::class),
            JSON_THROW_ON_ERROR
        );

        $livewire->js(
            'setTimeout(() => window.Livewire.getByName('.$targetName.').forEach(w => w.$refresh()), 0)'
        );
    }

    public static function dispatchMemberDetailInsightsRefresh(?Component $livewire): void
    {
        if ($livewire === null) {
            return;
        }

        $targetName = json_encode(
            app('livewire.factory')->resolveComponentName(MemberDetailInsightsWidget::class),
            JSON_THROW_ON_ERROR
        );

        $livewire->js(
            'setTimeout(() => window.Livewire.getByName('.$targetName.').forEach(w => w.$refresh()), 0)'
        );
    }
}
