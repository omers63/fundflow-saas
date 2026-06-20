<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Concerns;

use App\Filament\Tenant\Pages\AuditSystemPage;
use Illuminate\Contracts\Support\Htmlable;

trait EmbedsAsAuditWorkspacePanel
{
    public bool $embedded = false;

    public function mountEmbedded(bool $embedded = false): void
    {
        $this->embedded = $embedded;
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

    protected function embeddedWorkspaceUrl(string $sideTab): string
    {
        return AuditSystemPage::getUrl(['sideTab' => $sideTab]);
    }
}
