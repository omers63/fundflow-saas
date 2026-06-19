<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class TableRecordActionGroups
{
    /**
     * Configure row actions. When the list contains only a {@see ViewAction}, the row opens that
     * action on click and no actions column is rendered.
     *
     * @param  array<int, Action|ActionGroup>  $actions
     * @param  Closure(Model): (?string)|null  $recordUrl
     */
    public static function apply(Table $table, array $actions, ?Closure $recordUrl = null): Table
    {
        $flat = self::flatten($actions);

        if (self::isSingleViewAction($flat)) {
            /** @var ViewAction $view */
            $view = $flat[0];

            if ($recordUrl !== null) {
                return $table
                    ->recordUrl($recordUrl)
                    ->recordActions([]);
            }

            return $table
                ->recordActions([$view])
                ->recordUrl(fn (): ?string => null)
                ->recordAction($view->getName())
                ->recordActions([]);
        }

        $table = $table->recordActions(self::wrap($actions));

        if ($recordUrl !== null) {
            $table->recordUrl($recordUrl);
        }

        return $table;
    }

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

    /**
     * @param  array<int, Action|ActionGroup>  $actions
     * @return array<int, Action>
     */
    public static function flatten(array $actions): array
    {
        $flat = [];

        foreach ($actions as $action) {
            if ($action instanceof ActionGroup) {
                foreach ($action->getFlatActions() as $grouped) {
                    $flat[] = $grouped;
                }

                continue;
            }

            $flat[] = $action;
        }

        return $flat;
    }

    /**
     * @param  array<int, Action>  $actions
     */
    public static function isSingleViewAction(array $actions): bool
    {
        return count($actions) === 1 && $actions[0] instanceof ViewAction;
    }
}
