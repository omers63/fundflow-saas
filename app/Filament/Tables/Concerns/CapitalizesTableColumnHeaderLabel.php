<?php

namespace App\Filament\Tables\Concerns;

use App\Support\Lang;
use Illuminate\Contracts\Support\Htmlable;

trait CapitalizesTableColumnHeaderLabel
{
    public function getLabel(): string|Htmlable
    {
        $label = parent::getLabel();

        if ($label instanceof Htmlable) {
            return $label;
        }

        $string = trim((string) $label);

        if ($string === '') {
            return $label;
        }

        return Lang::formatUiLabel($string);
    }
}
