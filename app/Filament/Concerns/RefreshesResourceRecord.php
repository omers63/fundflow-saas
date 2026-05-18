<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

trait RefreshesResourceRecord
{
    protected function refreshResolvedRecord(): void
    {
        $this->record = $this->resolveRecord($this->getRecord()->getKey());

        if (method_exists($this, 'fillForm')) {
            $this->fillForm();
        }
    }
}
