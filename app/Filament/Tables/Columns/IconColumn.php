<?php

namespace App\Filament\Tables\Columns;

use App\Filament\Tables\Concerns\CapitalizesTableColumnHeaderLabel;
use Filament\Tables\Columns\IconColumn as FilamentIconColumn;

class IconColumn extends FilamentIconColumn
{
    use CapitalizesTableColumnHeaderLabel;
}
