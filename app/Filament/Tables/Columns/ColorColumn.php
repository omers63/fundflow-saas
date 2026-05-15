<?php

namespace App\Filament\Tables\Columns;

use App\Filament\Tables\Concerns\CapitalizesTableColumnHeaderLabel;
use Filament\Tables\Columns\ColorColumn as FilamentColorColumn;

class ColorColumn extends FilamentColorColumn
{
    use CapitalizesTableColumnHeaderLabel;
}
