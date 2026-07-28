<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Pages\Page;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;

/**
 * Icon-only header actions (page headers and table headers) with tooltips from labels.
 *
 * Application standard: page header actions and header ActionGroups are icon-only.
 * Use {@see apply()} / {@see applyGroup()} / {@see applyMany()}, or extend
 * {@see Page} which normalizes header actions automatically.
 */
final class TableHeaderIconAction
{
    public static function apply(Action $action): Action
    {
        $icon = $action->getIcon();

        if ($icon !== null) {
            $action->tableIcon($icon);
        }

        $action = $action->iconButton();

        if (blank($action->getTooltip())) {
            $action->tooltip(fn (): mixed => $action->getLabel());
        }

        return $action;
    }

    public static function applyGroup(ActionGroup $group): ActionGroup
    {
        $group = $group->iconButton();

        if (blank($group->getTooltip())) {
            $group->tooltip(fn (): mixed => $group->getLabel());
        }

        return $group;
    }

    /**
     * @param  list<Action|ActionGroup>  $actions
     * @return list<Action|ActionGroup>
     */
    public static function normalize(array $actions): array
    {
        return array_map(
            function (Action|ActionGroup $action): Action|ActionGroup {
                if ($action instanceof ActionGroup) {
                    return self::applyGroup($action);
                }

                return self::apply($action);
            },
            $actions,
        );
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
