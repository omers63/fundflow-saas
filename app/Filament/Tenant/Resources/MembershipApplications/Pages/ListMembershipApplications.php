<?php

namespace App\Filament\Tenant\Resources\MembershipApplications\Pages;

use App\Filament\Tenant\Resources\MembershipApplications\MembershipApplicationResource;
use App\Filament\Tenant\Widgets\MembershipApplicationInsightsWidget;
use App\Models\Tenant\MembershipApplication;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListMembershipApplications extends ListRecords
{
    protected static string $resource = MembershipApplicationResource::class;

    public function getTabs(): array
    {
        $pendingCount = MembershipApplication::pending()->count();
        $approvedCount = MembershipApplication::query()->where('status', 'approved')->count();
        $rejectedCount = MembershipApplication::query()->where('status', 'rejected')->count();

        return [
            'all' => Tab::make(MembershipApplicationResource::listTabLabel('all')),
            'pending' => Tab::make(MembershipApplicationResource::listTabLabel('pending'))
                ->badge($pendingCount > 0 ? (string) $pendingCount : null)
                ->badgeColor('warning'),
            'approved' => Tab::make(MembershipApplicationResource::listTabLabel('approved'))
                ->badge($approvedCount > 0 ? (string) $approvedCount : null)
                ->badgeColor('success'),
            'rejected' => Tab::make(MembershipApplicationResource::listTabLabel('rejected'))
                ->badge($rejectedCount > 0 ? (string) $rejectedCount : null)
                ->badgeColor('danger'),
        ];
    }

    protected function getTableQuery(): Builder
    {
        $query = parent::getTableQuery();
        $tab = MembershipApplicationResource::resolveListTab();

        return match ($tab) {
            'pending' => $query->where('status', 'pending'),
            'approved' => $query->where('status', 'approved'),
            'rejected' => $query->where('status', 'rejected'),
            default => $query,
        };
    }

    protected function getHeaderWidgets(): array
    {
        return [
            MembershipApplicationInsightsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getSubheading(): ?string
    {
        return match (MembershipApplicationResource::resolveListTab()) {
            'pending' => __('Applications awaiting document check, fee confirmation, and approval.'),
            'approved' => __('Approved applications — members were created on acceptance.'),
            'rejected' => __('Rejected applications kept for audit.'),
            default => __('Review new membership applications and manage the onboarding pipeline.'),
        };
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'ff-tenant-applications-workspace',
        ];
    }
}
