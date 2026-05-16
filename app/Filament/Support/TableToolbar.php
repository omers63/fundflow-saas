<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Livewire\Component;

final class TableToolbar
{
    /**
     * Refreshes the hosting Livewire table (list page, relation manager, or table widget).
     */
    public static function refreshBulkAction(): BulkAction
    {
        return BulkAction::make('refreshTable')
            ->label(__('Refresh'))
            ->icon('heroicon-o-arrow-path')
            ->action(function (Component $livewire): void {
                if (method_exists($livewire, 'resetTable')) {
                    $livewire->resetTable();
                }
            });
    }

    /**
     * @param  array<int, BulkAction>  $actions
     * @return array<int, BulkActionGroup>
     */
    public static function bulkGroup(array $actions): array
    {
        return [
            BulkActionGroup::make($actions),
        ];
    }
}
