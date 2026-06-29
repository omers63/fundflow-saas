<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Actions\Action;

/**
 * Consistent outlined toolbar buttons for ledger and import/export actions.
 */
final class LedgerToolbarAction
{
    public static function apply(Action $action): Action
    {
        $icon = $action->getIcon();

        if ($icon !== null) {
            $action->tableIcon($icon);
        }

        return $action->button()->outlined();
    }

    /**
     * @param  list<Action>  $actions
     * @return list<Action>
     */
    public static function applyMany(array $actions): array
    {
        return array_map(
            fn (Action $action): Action => self::apply($action),
            $actions,
        );
    }
}
