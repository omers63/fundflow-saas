<?php

declare(strict_types=1);

use App\Filament\Tables\Columns\CollectedEmiCashColumn;
use App\Filament\Tables\Columns\Summarizers\CollectedEmiCashSum;

it('registers exactly one collected cash sum summarizer', function (): void {
    $column = CollectedEmiCashColumn::make();

    $summarizers = $column->getSummarizers();

    expect($summarizers)->toHaveCount(1)
        ->and($summarizers[0])->toBeInstanceOf(CollectedEmiCashSum::class);
});
