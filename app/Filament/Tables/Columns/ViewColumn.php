<?php

namespace App\Filament\Tables\Columns;

use App\Filament\Tables\Concerns\CapitalizesTableColumnHeaderLabel;
use Filament\Tables\Columns\ViewColumn as FilamentViewColumn;

class ViewColumn extends FilamentViewColumn
{
    use CapitalizesTableColumnHeaderLabel;
}
