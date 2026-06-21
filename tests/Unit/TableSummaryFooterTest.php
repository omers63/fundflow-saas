<?php

use App\Filament\Support\AccountTransactionAmountColumn;
use App\Filament\Support\TableSummaryFooter;
use App\Models\Tenant\Setting;
use Filament\Facades\Filament;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\HtmlString;
use Tests\TestCase;

uses(TestCase::class);

it('attaches a single sum summarizer to qualifying money columns', function (): void {
    $column = TableSummaryFooter::applySummarizersToTextColumn(
        TextColumn::make('amount')->money('USD'),
    );

    $summarizers = $column->getSummarizers();

    expect($summarizers)->not()->toBeEmpty()
        ->and($summarizers)->toHaveCount(1)
        ->and($summarizers[0])->toBeInstanceOf(Sum::class);
});

it('does not attach summarizers to non-aggregatable text columns', function (): void {
    $column = TextColumn::make('name');

    expect($column->getSummarizers())->toBeEmpty();
});

it('uses a locale-aware closure label for sum summarizers', function (): void {
    app()->setLocale('ar');

    $column = TableSummaryFooter::applySummarizersToTextColumn(
        TextColumn::make('amount')->money('USD'),
    );

    $summarizers = $column->getSummarizers();

    expect($summarizers[0]->getLabel())->toBe('المبلغ');
});

it('translates ledger amount column footer label in arabic', function (): void {
    app()->setLocale('ar');
    Filament::setCurrentPanel('member');

    $summarizers = AccountTransactionAmountColumn::make()->getSummarizers();

    expect($summarizers)->toHaveCount(1)
        ->and($summarizers[0]->getLabel())->toBe('المبلغ');
});

it('renders riyal symbol markup in member money column footer summaries', function (): void {
    app()->setLocale('ar');
    Filament::setCurrentPanel('member');
    Setting::set('general', 'currency', 'SAR');

    $column = TableSummaryFooter::applySummarizersToTextColumn(
        TextColumn::make('amount')->money('SAR'),
    );

    $html = $column->getSummarizers()[0]->formatState(2500);

    expect($html)->toBeInstanceOf(HtmlString::class)
        ->and((string) $html)
        ->toContain('ff-sar-symbol')
        ->toContain('2,500.00')
        ->not->toMatch('/[٠-٩]/u');
});

it('formats non-money column footer summaries with western digits in arabic locale', function (): void {
    app()->setLocale('ar');
    Filament::setCurrentPanel('tenant');

    $column = TableSummaryFooter::applySummarizersToTextColumn(
        TextColumn::make('quantity'),
    );

    $formatted = $column->getSummarizers()[0]->formatState(1234.5);

    expect($formatted)->toBe('1,234.50')
        ->not->toMatch('/[٠-٩]/u');
});

it('formats numeric table cells with western digits in arabic locale', function (): void {
    app()->setLocale('ar');

    $column = TextColumn::make('amount_delta')->numeric(decimalPlaces: 2);

    expect($column->formatState(1500.25))->toBe('1,500.25')
        ->not->toMatch('/[٠-٩]/u');
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
