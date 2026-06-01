<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;

/**
 * Conventions for every Filament data table in this application.
 *
 * @see TableGrouping::apply() for collapsible group-by
 * @see AppServiceProvider for global striped(), selectable(), columnManager()
 */
final class TableStandards
{
    /**
     * Read-only grids: refresh bulk action inside a bulk group.
     *
     * @return array<int, BulkActionGroup>
     */
    public static function defaultToolbarActions(): array
    {
        return TableToolbar::bulkGroup([
            TableToolbar::refreshBulkAction(),
        ]);
    }

    /**
     * @param  array<int, BulkAction>  $actions
     * @return array<int, BulkActionGroup>
     */
    public static function toolbarWith(array $actions): array
    {
        return TableToolbar::bulkGroup($actions);
    }
}
