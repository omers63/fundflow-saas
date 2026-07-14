<?php

namespace App\Filament\Tables\Concerns;

use App\Filament\Support\UiLabelIcons;
use Illuminate\Contracts\Support\Htmlable;

trait CapitalizesTableColumnHeaderLabel
{
    public function getLabel(): string|Htmlable
    {
        $label = parent::getLabel();

        if ($label instanceof Htmlable && str_contains($label->toHtml() ?? '', 'fi-ff-label-with-icon')) {
            return $label;
        }

        if ($label instanceof Htmlable) {
            return UiLabelIcons::labeledHtml($label);
        }

        $string = trim((string) $label);

        if ($string === '') {
            return $label;
        }

        $icon = UiLabelIcons::forColumnName((string) $this->getName())
            ?? UiLabelIcons::forLabel($string)
            ?? UiLabelIcons::forKey('default');

        return UiLabelIcons::labeledHtml($string, $icon);
    }
}
