<?php

namespace App\Filament\Tables\Columns;

use App\Filament\Tables\Concerns\CapitalizesTableColumnHeaderLabel;
use Filament\Tables\Columns\ColumnGroup as FilamentColumnGroup;

class ColumnGroup extends FilamentColumnGroup
{
    use CapitalizesTableColumnHeaderLabel;
}
