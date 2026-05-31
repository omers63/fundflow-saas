<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Clusters;

use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\Loan;
use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class LoansCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_FUND_MANAGEMENT;

    protected static ?int $navigationSort = TenantNavigation::SORT_LOANS;

    protected static ?string $navigationLabel = 'Loans';

    protected static ?string $clusterBreadcrumb = 'Loans';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function getNavigationBadge(): ?string
    {
        $count = Loan::query()->inQueue()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
