<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;

final class TableRecordActionGroups
{
    /**
     * Ensure every table row action sits inside an {@see ActionGroup}. If the list already contains
     * only action groups (for example Credit / Debit splits), it is returned unchanged.
     *
     * @param  array<int, Action|ActionGroup>  $actions
     * @return array<int, ActionGroup>
     */
    public static function wrap(array $actions): array
    {
        if ($actions === []) {
            return [];
        }

        foreach ($actions as $action) {
            if (! $action instanceof ActionGroup) {
                return [
                    ActionGroup::make($actions)
                        ->label(__('Actions'))
                        ->icon('heroicon-o-ellipsis-vertical')
                        ->button(),
                ];
            }
        }

        /** @var array<int, ActionGroup> $actions */
        return $actions;
    }
}
