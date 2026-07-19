<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\MemberRequests;

use App\Filament\Concerns\TranslatesFilamentNavigationLabels;
use App\Filament\Tenant\Resources\MemberRequests\Pages\ListMemberRequests;
use App\Filament\Tenant\Resources\MemberRequests\Pages\ViewMemberRequest;
use App\Filament\Tenant\Resources\MemberRequests\Tables\MemberRequestsTable;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Filament\Tenant\Widgets\MemberRequestInsightsWidget;
use App\Models\Tenant\MemberRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Livewire\Component;
use Livewire\Livewire;
use UnitEnum;

class MemberRequestResource extends Resource
{
    use TranslatesFilamentNavigationLabels;

    protected static ?string $model = MemberRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_FUND_MANAGEMENT;

    protected static ?string $navigationLabel = 'Requests';

    protected static ?string $modelLabel = 'Member request';

    protected static ?string $pluralModelLabel = 'Member requests';

    protected static ?int $navigationSort = TenantNavigation::SORT_MEMBER_REQUESTS;

    /**
     * @return list<string>
     */
    public static function listTabKeys(): array
    {
        return ['all', 'pending', 'approved', 'rejected'];
    }

    public static function listTabLabel(string $tab): string
    {
        return match ($tab) {
            'pending' => __('Pending'),
            'approved' => __('Approved'),
            'rejected' => __('Rejected'),
            default => __('All requests'),
        };
    }

    public static function resolveListTab(): string
    {
        $livewire = Livewire::current();

        if ($livewire instanceof ListMemberRequests && filled($livewire->activeTab)) {
            $tab = $livewire->activeTab;
        } else {
            $tab = request()->string('tab')->toString() ?: 'all';
        }

        return in_array($tab, self::listTabKeys(), true) ? $tab : 'all';
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
            'view' => ViewMemberRequest::route('/{record}'),
        ];
    }

    public static function dispatchInsightsRefresh(?Component $livewire): void
    {
        if ($livewire === null) {
            return;
        }

        $targetName = json_encode(
            app('livewire.factory')->resolveComponentName(MemberRequestInsightsWidget::class),
            JSON_THROW_ON_ERROR
        );

        $livewire->js(
            'setTimeout(() => window.Livewire.getByName('.$targetName.').forEach(w => w.$refresh()), 0)'
        );
    }
}
