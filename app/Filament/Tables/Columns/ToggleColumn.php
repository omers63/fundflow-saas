<?php

namespace App\Filament\Tables\Columns;

use App\Filament\Tables\Concerns\CapitalizesTableColumnHeaderLabel;
use Filament\Tables\Columns\ToggleColumn as FilamentToggleColumn;

class ToggleColumn extends FilamentToggleColumn
{
    use CapitalizesTableColumnHeaderLabel;
}
