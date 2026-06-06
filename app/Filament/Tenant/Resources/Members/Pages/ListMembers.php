<?php

namespace App\Filament\Tenant\Resources\Members\Pages;

use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Widgets\MemberInsightsWidget;
use App\Models\Tenant\Member;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListMembers extends ListRecords
{
    protected static string $resource = MemberResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return match (MemberResource::resolveListTab()) {
            'delinquent' => __('Members blocked from portal access and new loans until arrears are cleared and status is restored.'),
            default => __('Manage the member roster, household structure, status, and contribution commitments.'),
        };
    }

    protected function getHeaderActions(): array
    {
        return [];
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

    public function getTabs(): array
    {
        return [
            'all' => Tab::make(MemberResource::listTabLabel('all')),
            'delinquent' => Tab::make(MemberResource::listTabLabel('delinquent'))
                ->badge((string) Member::query()->where('status', 'delinquent')->count())
                ->badgeColor('danger'),
        ];
    }

    protected function getTableQuery(): Builder
    {
        $query = parent::getTableQuery();

        if (MemberResource::resolveListTab() === 'delinquent') {
            return $query->where('status', 'delinquent');
        }

        return $query;
    }

    protected function getTableQueryStringIdentifier(): ?string
    {
        $tab = MemberResource::resolveListTab();

        return $tab === 'all' ? null : 'members-'.$tab;
    }
}
