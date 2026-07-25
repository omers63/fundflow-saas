<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;

/**
 * Icon-only table header actions (Import / Export / New) with tooltips from labels.
 */
final class TableHeaderIconAction
{
    public static function apply(Action $action): Action
    {
        $icon = $action->getIcon();

        if ($icon !== null) {
            $action->tableIcon($icon);
        }

        return $action
            ->iconButton()
            ->tooltip(fn (): mixed => $action->getLabel());
    }

    public static function applyGroup(ActionGroup $group): ActionGroup
    {
        return $group
            ->iconButton()
            ->tooltip(fn (): mixed => $group->getLabel());
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
