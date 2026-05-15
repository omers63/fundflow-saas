<?php

namespace App\Filament\Tables\Columns;

use App\Filament\Tables\Concerns\CapitalizesTableColumnHeaderLabel;
use Filament\Tables\Columns\SelectColumn as FilamentSelectColumn;

class SelectColumn extends FilamentSelectColumn
{
    use CapitalizesTableColumnHeaderLabel;
}
