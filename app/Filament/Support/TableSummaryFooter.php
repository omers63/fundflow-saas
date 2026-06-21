<?php

namespace App\Filament\Support;

use App\Filament\Tables\Columns\LedgerAmountColumn;
use App\Models\Tenant\Setting;
use Filament\Facades\Filament;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Number;

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

        if (
            in_array($column->getName(), [
                'balance_after',
                'allocated_amount',
                'available_amount',
                'active_exposure',
                'active_loans_count',
                'declared_pool',
                'tier_available',
            ], true)
        ) {
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

        $sum = Sum::make()->label(fn (): string => self::summarizeLabelForColumn($column));

        if (Filament::getCurrentPanel()?->getId() === 'member') {
            $currency = fn (): string => Setting::get('general', 'currency', 'USD');

            if (self::columnShouldFormatSummaryAsMoney($column)) {
                $sum
                    ->formatStateUsing(fn ($state): ?string => MoneyDisplay::tableSummaryHtml($state, $currency()))
                    ->html();
            } else {
                $sum->formatStateUsing(fn ($state): ?string => $state === null
                    ? null
                    : Number::format((float) $state, 2, locale: 'en'));
            }
        } elseif ($column->isMoney()) {
            $currency = fn (): string => Setting::get('general', 'currency', 'USD');

            $sum
                ->formatStateUsing(fn ($state): ?string => MoneyDisplay::tableSummaryHtml($state, $currency()))
                ->html();
        } else {
            $sum->numeric(decimalPlaces: 2, locale: 'en');
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

    private static function columnShouldFormatSummaryAsMoney(TextColumn $column): bool
    {
        if ($column->isMoney()) {
            return true;
        }

        $name = $column->getName();

        if (
            in_array($name, [
                'total_rows',
                'imported_rows',
                'duplicate_rows',
                'quantity',
                'active_loans_count',
            ], true)
        ) {
            return false;
        }

        return self::columnNameLooksAggregatable($name);
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
