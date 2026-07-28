<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\TableHeaderIconAction;
use Filament\Pages\Page as FilamentPage;

/**
 * Application page base: page header actions are icon-only with tooltips.
 *
 * Prefer extending this instead of Filament's Page directly so header actions
 * stay compact and consistent across tenant and member panels.
 */
abstract class Page extends FilamentPage
{
    public function cacheInteractsWithHeaderActions(): void
    {
        parent::cacheInteractsWithHeaderActions();

        $this->cachedHeaderActions = TableHeaderIconAction::normalize($this->cachedHeaderActions);
    }
}
