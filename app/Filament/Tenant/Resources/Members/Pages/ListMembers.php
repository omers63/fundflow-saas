<?php

namespace App\Filament\Tenant\Resources\Members\Pages;

use App\Filament\Tenant\Resources\Members\MemberResource;
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

    public function getTabs(): array
    {
        $delinquentCount = Member::query()->where('status', 'delinquent')->count();
        $suspendedCount = Member::query()->where('status', 'suspended')->count();
        $withdrawnCount = Member::query()->where('status', 'withdrawn')->count();

        return [
            'all' => Tab::make(MemberResource::listTabLabel('all')),
            'active' => Tab::make(MemberResource::listTabLabel('active')),
            'delinquent' => Tab::make(MemberResource::listTabLabel('delinquent'))
                ->badge($delinquentCount > 0 ? (string) $delinquentCount : null)
                ->badgeColor('danger'),
            'suspended' => Tab::make(MemberResource::listTabLabel('suspended'))
                ->badge($suspendedCount > 0 ? (string) $suspendedCount : null)
                ->badgeColor('warning'),
            'withdrawn' => Tab::make(MemberResource::listTabLabel('withdrawn'))
                ->badge($withdrawnCount > 0 ? (string) $withdrawnCount : null)
                ->badgeColor('gray'),
        ];
    }

    protected function getTableQuery(): Builder
    {
        $query = parent::getTableQuery();
        $tab = MemberResource::resolveListTab();

        return match ($tab) {
            'active' => $query->where('status', 'active'),
            'delinquent' => $query->where('status', 'delinquent'),
            'suspended' => $query->where('status', 'suspended'),
            'withdrawn' => $query->where('status', 'withdrawn'),
            default => $query,
        };
    }

    protected function getTableQueryStringIdentifier(): ?string
    {
        $tab = MemberResource::resolveListTab();

        return $tab === 'all' ? null : 'members-'.$tab;
    }
}
