<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Clusters;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class LoansCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Fund Management';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Loans';

    protected static ?string $clusterBreadcrumb = 'Loans';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
}
