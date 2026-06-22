<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Concerns;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;

trait EmbedsAsBankClearingWorkspacePanel
{
    /**
     * @var array<Action|ActionGroup>
     */
    protected array $cachedWorkspacePanelActions = [];

    public function bootEmbedsAsBankClearingWorkspacePanel(): void
    {
        if (! method_exists($this, 'workspacePanelActions')) {
            return;
        }

        $this->cacheWorkspacePanelActions($this->workspacePanelActions());
    }

    /**
     * @param  array<Action|ActionGroup>  $actions
     */
    protected function cacheWorkspacePanelActions(array $actions): void
    {
        $this->cachedWorkspacePanelActions = [];

        foreach ($actions as $action) {
            if ($action instanceof ActionGroup) {
                $action->livewire($this);

                if (! $action->getDropdownPlacement()) {
                    $action->dropdownPlacement('bottom-end');
                }

                /** @var array<string, Action> $flatActions */
                $flatActions = $action->getFlatActions();

                $this->mergeCachedActions($flatActions);
                $this->cachedWorkspacePanelActions[] = $action;

                continue;
            }

            $this->cacheAction($action);
            $this->cachedWorkspacePanelActions[] = $action;
        }
    }

    /**
     * @return array<Action|ActionGroup>
     */
    public function getCachedWorkspacePanelActions(): array
    {
        return $this->cachedWorkspacePanelActions;
    }
}
