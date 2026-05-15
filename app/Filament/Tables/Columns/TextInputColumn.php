<?php

namespace App\Filament\Tables\Columns;

use App\Filament\Tables\Concerns\CapitalizesTableColumnHeaderLabel;
use Filament\Tables\Columns\TextInputColumn as FilamentTextInputColumn;

class TextInputColumn extends FilamentTextInputColumn
{
    use CapitalizesTableColumnHeaderLabel;
}
