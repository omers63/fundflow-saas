<?php

use App\Filament\Support\AccountTransactionAmountColumn;
use App\Filament\Support\TableSummaryFooter;
use App\Filament\Tables\Columns\Summarizers\SignedLedgerSum;
use Filament\Tables\Columns\TextColumn;
use Tests\TestCase;

uses(TestCase::class);

it('registers exactly one signed sum summarizer on ledger amount columns', function (): void {
    $column = AccountTransactionAmountColumn::make();

    $summarizers = $column->getSummarizers();

    expect($summarizers)->toHaveCount(1)
        ->and($summarizers[0])->toBeInstanceOf(SignedLedgerSum::class);
});

it('does not summarize balance_after columns', function (): void {
    $column = TableSummaryFooter::applySummarizersToTextColumn(
        TextColumn::make('balance_after')->money('USD'),
    );

    expect($column->getSummarizers())->toBeEmpty();
});
