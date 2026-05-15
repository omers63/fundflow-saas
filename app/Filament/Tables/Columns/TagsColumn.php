<?php

namespace App\Filament\Tables\Columns;

use App\Filament\Tables\Concerns\CapitalizesTableColumnHeaderLabel;
use Filament\Tables\Columns\TagsColumn as FilamentTagsColumn;

class TagsColumn extends FilamentTagsColumn
{
    use CapitalizesTableColumnHeaderLabel;
}
