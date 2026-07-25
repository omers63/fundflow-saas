<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Actions\Action;

/**
 * Consistent icon-only toolbar buttons for ledger and import/export actions.
 */
final class LedgerToolbarAction
{
    public static function apply(Action $action): Action
    {
        return TableHeaderIconAction::apply($action);
    }

    /**
     * @param  list<Action>  $actions
     * @return list<Action>
     */
    public static function applyMany(array $actions): array
    {
        return TableHeaderIconAction::applyMany($actions);
    }
}
