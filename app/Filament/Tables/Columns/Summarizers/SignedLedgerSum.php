<?php

namespace App\Filament\Tables\Columns\Summarizers;

use Filament\Tables\Columns\Summarizers\Sum;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Support\Facades\DB;

class SignedLedgerSum extends Sum
{
    /**
     * Filament table footers aggregate via {@see getSelectStatements()}, not {@see Summarizer::using()}.
     *
     * @return array<string, string>
     */
    public function getSelectStatements(string $column): array
    {
        $query = $this->getQuery();
        $grammar = $query->getGrammar();
        $amountColumn = $grammar->wrap($column);
        $typeColumn = $grammar->wrap($query->getModel()->qualifyColumn('type'));

        $expression = "sum(CASE WHEN {$typeColumn} = 'debit' THEN -ABS({$amountColumn}) ELSE ABS({$amountColumn}) END)";

        return [
            $this->getSelectAlias() => $expression,
        ];
    }

    public function summarize(Builder $query, string $attribute): int|float|null
    {
        $grammar = $query->getGrammar();
        $amountColumn = $grammar->wrap($attribute);
        $typeColumn = $grammar->wrap($this->qualifiedTypeColumn($grammar));

        return $query->sum(
            DB::raw("CASE WHEN {$typeColumn} = 'debit' THEN -ABS({$amountColumn}) ELSE ABS({$amountColumn}) END"),
        );
    }

    private function qualifiedTypeColumn(Grammar $grammar): string
    {
        $table = $this->getQuery()->from;

        if (is_string($table)) {
            return "{$table}.type";
        }

        return $this->getQuery()->getModel()->qualifyColumn('type');
    }
}
