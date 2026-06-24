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
use App\Filament\Tenant\Resources\Members\RelationManagers\GuarantorExposureRelationManager;
use App\Filament\Tenant\Resources\Members\RelationManagers\LoansRelationManager;
use App\Filament\Tenant\Resources\Members\RelationManagers\MemberTransactionsTabsRelationManager;
use App\Filament\Tenant\Resources\Members\RelationManagers\MessagesRelationManager;
use App\Filament\Tenant\Resources\Members\RelationManagers\RepaymentsRelationManager;
use App\Filament\Tenant\Resources\Members\Schemas\MemberForm;
use App\Filament\Tenant\Resources\Members\Tables\MembersTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Filament\Tenant\Widgets\MemberDetailInsightsWidget;
use App\Filament\Tenant\Widgets\MemberInsightsWidget;
use App\Models\Tenant\Member;
use App\Services\Tenant\MemberListTabService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Livewire\Component;
use Livewire\Livewire;
use UnitEnum;

class MemberResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = Member::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_FUND_MANAGEMENT;

    protected static ?string $navigationLabel = 'Members';

    protected static ?string $modelLabel = 'Member';

    protected static ?string $pluralModelLabel = 'Members';

    protected static ?int $navigationSort = TenantNavigation::SORT_MEMBERS;

    public static function getNavigationBadge(): ?string
    {
        $count = Member::query()->where('status', 'delinquent')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /**
     * @return list<string>
     */
    public static function listTabKeys(): array
    {
        return app(MemberListTabService::class)->tabKeys();
    }

    public static function listTabLabel(string $tab): string
    {
        return app(MemberListTabService::class)->tabLabel($tab);
    }

    public static function listTabUrl(string $tab): string
    {
        return static::listUrl($tab);
    }

    /**
     * @param  array<string, array<string, mixed>>  $filters
     */
    public static function listUrl(string $tab = 'all', array $filters = []): string
    {
        $parameters = [];

        if ($tab !== 'all') {
            $parameters['tab'] = $tab;
        }

        if ($filters !== []) {
            $parameters['filters'] = $filters;
        }

        return static::getUrl('index', $parameters);
    }

    public static function resolveListTab(): string
    {
        $livewire = Livewire::current();

        if ($livewire instanceof ListMembers && filled($livewire->activeTab)) {
            $tab = $livewire->activeTab;
        } else {
            $tab = request()->string('tab')->toString() ?: 'all';
        }

        return in_array($tab, self::listTabKeys(), true) ? $tab : 'all';
    }

    public static function migrationPendingCount(): int
    {
        return app(MemberListTabService::class)->tabCounts()['migration_pending'] ?? 0;
    }

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
            LoansRelationManager::class,
            ContributionsRelationManager::class,
            MemberTransactionsTabsRelationManager::class,
            AccountsRelationManager::class,
            RepaymentsRelationManager::class,
            DependentsRelationManager::class,
            GuarantorExposureRelationManager::class,
            MessagesRelationManager::class,
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

    /**
     * Workspace URL with a specific relation manager tab active (uses Filament's `relation` query param).
     */
    public static function workspaceUrl(Member|int|string $record, ?string $relationManagerClass = null): string
    {
        $recordKey = $record instanceof Member ? $record->getKey() : $record;
        $parameters = ['record' => $recordKey];

        if ($relationManagerClass !== null) {
            $managerIndex = array_search($relationManagerClass, static::getRelations(), true);

            if ($managerIndex !== false) {
                $parameters['relation'] = (string) $managerIndex;
            }
        }

        return static::getUrl('view', $parameters);
    }

    /**
     * @deprecated Use {@see workspaceUrl()} — kept for callers that still reference edit URLs with relation tabs.
     */
    public static function editUrlWithRelationManager(Member|int|string $record, string $relationManagerClass): string
    {
        return static::workspaceUrl($record, $relationManagerClass);
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
