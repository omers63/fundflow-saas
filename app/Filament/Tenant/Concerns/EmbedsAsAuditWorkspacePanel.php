<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Concerns;

use App\Filament\Tenant\Pages\AuditSystemPage;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Illuminate\Contracts\Support\Htmlable;

trait EmbedsAsAuditWorkspacePanel
{
    public bool $embedded = false;

    /**
     * @var array<Action|ActionGroup>
     */
    protected array $cachedWorkspacePanelActions = [];

    public function mountEmbedded(bool $embedded = false): void
    {
        $this->embedded = $embedded;
    }

    public function bootEmbedsAsAuditWorkspacePanel(): void
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

    public function getLayout(): string
    {
        if ($this->embedded) {
            return 'filament.tenant.layouts.embedded-workspace';
        }

        return parent::getLayout();
    }

    public function getView(): string
    {
        if ($this->embedded && property_exists($this, 'embeddedView') && filled($this->embeddedView)) {
            return $this->embeddedView;
        }

        return parent::getView();
    }

    public function getHeading(): string|Htmlable|null
    {
        if ($this->embedded) {
            return null;
        }

        return parent::getHeading();
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

    /**
     * @param  array<Action|ActionGroup>  $actions
     * @return array<Action|ActionGroup>
     */
    protected function headerActionsUnlessEmbedded(array $actions): array
    {
        if ($this->embedded) {
            return [];
        }

        return $actions;
    }

    protected function embeddedWorkspaceUrl(string $sideTab): string
    {
        return AuditSystemPage::getUrl(['sideTab' => $sideTab]);
    }
}
