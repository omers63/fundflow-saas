<?php

use App\Filament\Support\AccountTransactionAmountColumn;
use App\Filament\Support\TableSummaryFooter;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Tests\TestCase;

uses(TestCase::class);

it('attaches a single sum summarizer to qualifying money columns', function (): void {
    $column = TextColumn::make('amount')->money('USD');

    $summarizers = $column->getSummarizers();

    expect($summarizers)->not()->toBeEmpty()
        ->and($summarizers)->toHaveCount(1)
        ->and($summarizers[0])->toBeInstanceOf(Sum::class);
});

it('does not attach summarizers to non-aggregatable text columns', function (): void {
    $column = TextColumn::make('name');

    expect($column->getSummarizers())->toBeEmpty();
});

it('does not attach summarizers to ledger amount columns', function (): void {
    expect(AccountTransactionAmountColumn::make()->getSummarizers())->toHaveCount(1);
});

it('detects aggregatable column names', function (string $column, bool $expected) {
    expect(TableSummaryFooter::columnNameLooksAggregatable($column))->toBe($expected);
})->with([
    ['amount', true],
    ['monthly_contribution_amount', true],
    ['custom_amount', true],
    ['balance', true],
    ['total_rows', true],
    ['name', false],
    ['member.name', false],
    ['status', false],
    ['interest_rate', false],
    ['term_months', false],
]);
