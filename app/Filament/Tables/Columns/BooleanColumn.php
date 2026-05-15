<?php

namespace App\Filament\Tables\Columns;

use App\Filament\Tables\Concerns\CapitalizesTableColumnHeaderLabel;
use Filament\Tables\Columns\BooleanColumn as FilamentBooleanColumn;

class BooleanColumn extends FilamentBooleanColumn
{
    use CapitalizesTableColumnHeaderLabel;
}
