<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Forms\Components\Select;

final class MemberLedgerTagSelect
{
    public static function make(string $name = 'member_id'): Select
    {
        return MemberSelect::configure(
            Select::make($name)
                ->label(__('Member tag'))
                ->helperText(__('Optional for master accounts — ties this line to a member in filters and reports.'))
                ->placeholder(__('—')),
            activeOnly: false,
        );
    }
}
