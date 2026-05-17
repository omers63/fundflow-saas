<?php

declare(strict_types=1);

use App\Support\Lang;

if (! function_exists('ui_label')) {
    /**
     * Title-case a UI label for display (widgets, cards, KPI subs, etc.).
     */
    function ui_label(string $label): string
    {
        return Lang::formatUiLabel($label);
    }
}
