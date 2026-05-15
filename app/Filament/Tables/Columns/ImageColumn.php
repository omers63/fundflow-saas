<?php

namespace App\Filament\Tables\Columns;

use App\Filament\Tables\Concerns\CapitalizesTableColumnHeaderLabel;
use Filament\Tables\Columns\ImageColumn as FilamentImageColumn;

class ImageColumn extends FilamentImageColumn
{
    use CapitalizesTableColumnHeaderLabel;
}
