<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\Transaction;

final class AccountTransactionTypeFilter
{
    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'credit' => Transaction::typeLabel('credit'),
            'debit' => Transaction::typeLabel('debit'),
        ];
    }
}
