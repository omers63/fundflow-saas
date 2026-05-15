<?php

namespace App\Filament\Tables\Columns;

use App\Filament\Tables\Concerns\CapitalizesTableColumnHeaderLabel;
use Filament\Tables\Columns\CheckboxColumn as FilamentCheckboxColumn;

class CheckboxColumn extends FilamentCheckboxColumn
{
    use CapitalizesTableColumnHeaderLabel;
}
