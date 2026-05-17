<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Support\Lang;
use Illuminate\Contracts\Support\Htmlable;

trait FormatsFilamentLabel
{
    public function getLabel(): string|Htmlable|null
    {
        $label = parent::getLabel();

        if (! is_string($label)) {
            return $label;
        }

        $string = trim($label);

        if ($string === '') {
            return $label;
        }

        return Lang::formatUiLabel($string);
    }
}
