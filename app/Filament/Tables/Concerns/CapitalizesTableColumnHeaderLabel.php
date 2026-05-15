<?php

namespace App\Filament\Tables\Concerns;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

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

        return Str::ucfirst($string);
    }
}
