<?php

namespace App\Filament\Tables\Columns;

use App\Filament\Tables\Concerns\CapitalizesTableColumnHeaderLabel;
use Filament\Tables\Columns\BadgeColumn as FilamentBadgeColumn;

class BadgeColumn extends FilamentBadgeColumn
{
    use CapitalizesTableColumnHeaderLabel;
}
