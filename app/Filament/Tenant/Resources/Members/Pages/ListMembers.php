<?php

namespace App\Filament\Tenant\Resources\Members\Pages;

use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Resources\MembershipApplications\MembershipApplicationResource;
use App\Filament\Tenant\Widgets\MemberInsightsWidget;
use App\Services\Tenant\MemberListTabService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListMembers extends ListRecords
{
    protected static string $resource = MemberResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return match (MemberResource::resolveListTab()) {
            'delinquent' => __('Members blocked from portal access and new loans until arrears are cleared and status is restored.'),
            'migration_pending' => __('Imported members awaiting contribution cycle clearance before full go-live.'),
            'suspended' => __('Members temporarily blocked from portal access and new loans.'),
            default => __('Manage the member roster, household structure, status, and contribution commitments.'),
        };
    }

    protected function getHeaderWidgets(): array
    {
        return [
            MemberInsightsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pending_applications')
                ->label(__('Applications'))
                ->icon('heroicon-o-clipboard-document-list')
                ->color('gray')
                ->badge(MembershipApplicationResource::getNavigationBadge())
                ->badgeColor(MembershipApplicationResource::getNavigationBadgeColor())
                ->url(MembershipApplicationResource::listTabUrl('pending')),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                View::make('filament.tenant.resources.members.partials.status-filter-pills-wrapper'),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'fi-page-members-list',
        ];
    }

    protected function getTableQuery(): Builder
    {
        $query = parent::getTableQuery();

        return app(MemberListTabService::class)->applyTabFilter(
            $query,
            MemberResource::resolveListTab(),
        );
    }

    protected function getTableQueryStringIdentifier(): ?string
    {
        $tab = MemberResource::resolveListTab();

        return $tab === 'all' ? null : 'members-'.$tab;
    }
}
