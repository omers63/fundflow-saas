<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class TableRecordActionGroups
{
    /**
     * Configure row actions. When the list contains only a {@see ViewAction} or {@see EditAction},
     * the row opens that action on click and no actions column is rendered.
     *
     * @param  array<int, Action|ActionGroup>  $actions
     * @param  Closure(Model): (?string)|null  $recordUrl
     */
    public static function apply(Table $table, array $actions, ?Closure $recordUrl = null): Table
    {
        $flat = self::flatten($actions);

        if (self::isSinglePrimaryAction($flat)) {
            return self::configureSinglePrimaryActionRowClick($table, $flat[0], $recordUrl);
        }

        $table = $table->recordActions(self::wrap($actions));

        if ($recordUrl !== null) {
            $table->recordUrl($recordUrl);
        }

        return $table;
    }

    /**
     * When a table already has a single view or edit row action, remove the actions column and
     * rely on row click (or an existing {@see Table::recordUrl()}).
     */
    public static function normalizeSinglePrimaryActionRowClick(Table $table): Table
    {
        if ($table->getRecordActions() === []) {
            return $table;
        }

        $flat = self::flatten($table->getRecordActions());

        if (! self::isSinglePrimaryAction($flat)) {
            return $table;
        }

        return self::configureSinglePrimaryActionRowClick($table, $flat[0]);
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

    /**
     * @param  array<int, Action>  $actions
     */
    public static function isSingleEditAction(array $actions): bool
    {
        return count($actions) === 1 && $actions[0] instanceof EditAction;
    }

    /**
     * @param  array<int, Action>  $actions
     */
    public static function isSinglePrimaryAction(array $actions): bool
    {
        return self::isSingleViewAction($actions) || self::isSingleEditAction($actions);
    }

    private static function configureSinglePrimaryActionRowClick(
        Table $table,
        Action $action,
        ?Closure $recordUrl = null,
    ): Table {
        if ($recordUrl !== null) {
            return $table
                ->recordUrl($recordUrl)
                ->recordActions([]);
        }

        if ($table->hasCustomRecordUrl()) {
            return $table->recordActions([]);
        }

        return $table
            ->recordUrl(fn (): ?string => null)
            ->recordAction($action->getName())
            ->recordActions([]);
    }
}
