<?php

namespace App\Filament\Support;

use App\Filament\Tables\Columns\LedgerAmountColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

final class TableSummaryFooter
{
    public static function applyToTable(Table $table): Table
    {
        return $table->summaries(
            pageCondition: false,
            allTableCondition: true,
        );
    }

    public static function applySummarizersToTextColumn(TextColumn $column): TextColumn
    {
        if ($column instanceof LedgerAmountColumn) {
            return $column;
        }

        if (str_contains($column->getName(), '.')) {
            return $column;
        }

        if (in_array($column->getName(), ['balance_after'], true)) {
            return $column;
        }

        if (! self::columnQualifiesForSummaries($column)) {
            return $column;
        }

        // If the column already has summarizers (e.g. AccountTransactionAmountColumn::make()),
        // do not attach an additional one.
        if ($column->getSummarizers()) {
            return $column;
        }

        $label = self::summarizeLabelForColumn($column);

        $sum = Sum::make()->label($label);

        if ($column->isMoney()) {
            $sum->money(decimalPlaces: 2);
        } else {
            $sum->numeric(decimalPlaces: 2);
        }

        return $column->summarize([$sum]);
    }

    public static function columnNameLooksAggregatable(string $name): bool
    {
        if (str_contains($name, '.')) {
            return false;
        }

        if (preg_match('/_amount$/i', $name)) {
            return true;
        }

        return (bool) preg_match(
            '/^(amount|balance|price|quantity|total_rows|imported_rows|duplicate_rows|monthly_contribution_amount|monthly_repayment|total_repaid)$/i',
            $name
        );
    }

    private static function columnQualifiesForSummaries(TextColumn $column): bool
    {
        if ($column->isMoney()) {
            return true;
        }

        return self::columnNameLooksAggregatable($column->getName());
    }

    private static function summarizeLabelForColumn(TextColumn $column): string
    {
        $label = $column->getLabel();

        if ($label instanceof Htmlable) {
            $text = trim(html_entity_decode(strip_tags($label->toHtml()), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        } else {
            $text = trim((string) $label);
        }

        if ($text !== '') {
            return $text;
        }

        return str($column->getName())
            ->replace('_', ' ')
            ->headline()
            ->toString();
    }
}
