<?php

declare(strict_types=1);

namespace App\Filament\Tables\Columns\Summarizers;

use Filament\Tables\Columns\Summarizers\Sum;

/**
 * Footer total for process-queue remaining-to-disburse (approved − disbursed, floored at 0).
 */
class LoanRemainingToDisburseSum extends Sum
{
    /**
     * @return array<string, string>
     */
    public function getSelectStatements(string $column): array
    {
        $query = $this->getQuery();
        $grammar = $query->getGrammar();
        $approved = $grammar->wrap($query->getModel()->qualifyColumn('amount_approved'));
        $disbursed = $grammar->wrap($query->getModel()->qualifyColumn('amount_disbursed'));

        return [
            $this->getSelectAlias() => "sum(GREATEST(0, COALESCE({$approved}, 0) - COALESCE({$disbursed}, 0)))",
        ];
    }
}
