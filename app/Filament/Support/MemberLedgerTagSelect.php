<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\Member;
use Filament\Forms\Components\Select;

final class MemberLedgerTagSelect
{
    public static function make(string $name = 'member_id'): Select
    {
        return Select::make($name)
            ->label(__('Member tag'))
            ->helperText(__('Optional for master accounts — ties this line to a member in filters and reports.'))
            ->options(fn (): array => Member::query()
                ->orderBy('member_number')
                ->get()
                ->mapWithKeys(fn (Member $member): array => [
                    $member->id => trim("{$member->member_number} – {$member->name}"),
                ])
                ->all())
            ->searchable()
            ->placeholder(__('—'));
    }
}
