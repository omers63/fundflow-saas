<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Clusters;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanEmiCollectionCatalogService;
use App\Support\Lang;
use App\Support\TenantRuntimeCache;
use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class LoansCluster extends Cluster
{
    use TranslatesPageNavigationLabel;

    public const OPEN_CYCLE_UNCOLLECTED_CACHE_KEY = 'loans_cluster:open_cycle_uncollected_count';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_FUND_MANAGEMENT;

    protected static ?int $navigationSort = TenantNavigation::SORT_LOANS;

    protected static ?string $navigationLabel = 'Loans';

    protected static ?string $clusterBreadcrumb = 'Loans';

    protected static bool $shouldRegisterSubNavigation = false;

    public static function getClusterBreadcrumb(): ?string
    {
        $breadcrumb = parent::getClusterBreadcrumb();

        if ($breadcrumb === null || $breadcrumb === '') {
            return $breadcrumb;
        }

        return Lang::formatUiLabel(__($breadcrumb));
    }

    /**
     * Sidebar pill: members who still owe an EMI for the OPEN cycle (the Collection tab's "Uncollected" count),
     * independent of whichever cycle is being browsed on the Loans page.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = self::openCycleUncollectedCount();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function openCycleUncollectedCount(): int
    {
        return (int) TenantRuntimeCache::remember(
            self::OPEN_CYCLE_UNCOLLECTED_CACHE_KEY,
            60,
            function (): int {
                [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();

                return app(LoanEmiCollectionCatalogService::class)->pendingMemberCount($month, $year);
            },
        );
    }

    public static function forgetOpenCycleUncollectedCount(): void
    {
        TenantRuntimeCache::forget(self::OPEN_CYCLE_UNCOLLECTED_CACHE_KEY);
    }
}
