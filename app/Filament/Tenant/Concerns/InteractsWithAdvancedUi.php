<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Concerns;

use Livewire\Attributes\Url;

trait InteractsWithAdvancedUi
{
    public const ADVANCED_UI_SESSION_KEY = 'tenant.advanced_ui';

    #[Url(as: 'advanced')]
    public bool $advancedUi = false;

    public function mountAdvancedUi(): void
    {
        if (request()->has('advanced')) {
            $this->syncAdvancedUiSession();

            return;
        }

        $this->advancedUi = (bool) session(self::ADVANCED_UI_SESSION_KEY, false);
    }

    public function setAdvancedUi(bool $enabled): void
    {
        if (! $this->advancedUiAvailable()) {
            return;
        }

        if ($this->advancedUi === $enabled) {
            return;
        }

        $this->advancedUi = $enabled;
        $this->syncAdvancedUiSession();
        $this->onAdvancedUiToggled();
    }

    public function advancedUiAvailable(): bool
    {
        return auth('tenant')->user()?->is_admin === true;
    }

    protected function syncAdvancedUiSession(): void
    {
        if ($this->advancedUi && $this->advancedUiAvailable()) {
            session([self::ADVANCED_UI_SESSION_KEY => true]);

            return;
        }

        $this->advancedUi = false;
        session()->forget(self::ADVANCED_UI_SESSION_KEY);
    }

    protected function onAdvancedUiToggled(): void
    {
        // Hook for pages that need to reset tabs when advanced mode changes.
    }
}
