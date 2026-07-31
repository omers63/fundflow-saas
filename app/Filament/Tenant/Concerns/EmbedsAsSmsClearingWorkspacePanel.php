<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Concerns;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;

trait EmbedsAsSmsClearingWorkspacePanel
{
    /**
     * @var array<Action|ActionGroup>
     */
    protected array $cachedWorkspacePanelActions = [];

    public function bootEmbedsAsSmsClearingWorkspacePanel(): void
    {
        if ($this->cachedWorkspacePanelActions !== []) {
            return;
        }

        $this->refreshWorkspacePanelActions();
    }

    public function refreshWorkspacePanelActions(): void
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
        foreach ($this->cachedWorkspacePanelActions as $previous) {
            if ($previous instanceof Action) {
                unset($this->cachedActions[$previous->getName()]);
            }

            if ($previous instanceof ActionGroup) {
                foreach ($previous->getFlatActions() as $flatAction) {
                    unset($this->cachedActions[$flatAction->getName()]);
                }
            }
        }

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
