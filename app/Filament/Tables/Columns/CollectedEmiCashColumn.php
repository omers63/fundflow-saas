<?php

declare(strict_types=1);

namespace App\Filament\Tables\Columns;

use App\Filament\Tables\Columns\Summarizers\CollectedEmiCashSum;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Setting;
use Illuminate\Database\Eloquent\Builder;

class CollectedEmiCashColumn extends TextColumn
{
    protected function setUp(): void
    {
        $currency = fn (): string => Setting::get('general', 'currency', 'USD');

        $this
            ->state(fn (LoanInstallment $record): float => $record->collectedCashAmount())
            ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('amount', $direction))
            ->money($currency)
            ->summarize([
                CollectedEmiCashSum::make(),
            ]);
    }

    public static function make(?string $name = 'amount'): static
    {
        return parent::make($name);
    }
}
