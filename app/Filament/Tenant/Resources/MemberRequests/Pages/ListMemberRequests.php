<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\MemberRequests\Pages;

use App\Filament\Tenant\Resources\MemberRequests\MemberRequestResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Widgets\MemberRequestInsightsWidget;
use App\Models\Tenant\MemberRequest;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListMemberRequests extends ListRecords
{
    protected static string $resource = MemberRequestResource::class;

    public function getSubheading(): ?string
    {
        return match (MemberRequestResource::resolveListTab()) {
            'pending' => __('Allocation and household changes waiting for admin approval.'),
            'approved' => __('Requests that were applied to member records.'),
            'rejected' => __('Declined requests with optional notes to members.'),
            default => __('Review allocation and family changes submitted by members.'),
        };
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'ff-tenant-member-requests-workspace',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToMembers')
                ->label(__('Members'))
                ->icon('heroicon-o-users')
                ->color('gray')
                ->url(MemberResource::getUrl('index')),
        ];
    }

    public function getTabs(): array
    {
        $pendingCount = MemberRequest::query()->where('status', MemberRequest::STATUS_PENDING)->count();
        $approvedCount = MemberRequest::query()->where('status', MemberRequest::STATUS_APPROVED)->count();
        $rejectedCount = MemberRequest::query()->where('status', MemberRequest::STATUS_REJECTED)->count();

        return [
            'all' => Tab::make(MemberRequestResource::listTabLabel('all')),
            'pending' => Tab::make(MemberRequestResource::listTabLabel('pending'))
                ->badge($pendingCount > 0 ? (string) $pendingCount : null)
                ->badgeColor('warning'),
            'approved' => Tab::make(MemberRequestResource::listTabLabel('approved'))
                ->badge($approvedCount > 0 ? (string) $approvedCount : null)
                ->badgeColor('success'),
            'rejected' => Tab::make(MemberRequestResource::listTabLabel('rejected'))
                ->badge($rejectedCount > 0 ? (string) $rejectedCount : null)
                ->badgeColor('danger'),
        ];
    }

    protected function getTableQuery(): Builder
    {
        $query = parent::getTableQuery();
        $tab = MemberRequestResource::resolveListTab();

        return match ($tab) {
            'pending' => $query->where('status', MemberRequest::STATUS_PENDING),
            'approved' => $query->where('status', MemberRequest::STATUS_APPROVED),
            'rejected' => $query->where('status', MemberRequest::STATUS_REJECTED),
            default => $query,
        };
    }

    protected function getHeaderWidgets(): array
    {
        return [
            MemberRequestInsightsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    protected function applyFiltersToTableQuery(Builder $query, bool $isResolvingRecord = false): Builder
    {
        if ($query->getModel() === null) {
            $query = MemberRequest::query();
        }

        return parent::applyFiltersToTableQuery($query, $isResolvingRecord);
    }

    public function filterTableQuery(Builder $query): Builder
    {
        if ($query->getModel() === null) {
            $query = MemberRequest::query();
        }

        return parent::filterTableQuery($query);
    }

    public function getTableQueryForExport(): Builder
    {
        $query = $this->getTable()->getQuery();

        if ($query->getModel() === null) {
            $query = MemberRequest::query();
        }

        $this->applyFiltersToTableQuery($query);
        $this->applySearchToTableQuery($query);
        $this->applySortingToTableQuery($query);

        return $query;
    }
}
