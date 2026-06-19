<?php

declare(strict_types=1);

use App\Filament\Support\AccountTransactionTypeFilter;
use App\Models\Tenant\Transaction;
use Tests\TestCase;

uses(TestCase::class);

it('translates transaction type labels in arabic', function (): void {
    app()->setLocale('ar');

    expect(Transaction::typeLabel('credit'))->toBe('دائن')
        ->and(Transaction::typeLabel('debit'))->toBe('مدين');
});

it('exposes translated filter options for transaction types', function (): void {
    app()->setLocale('ar');

    expect(AccountTransactionTypeFilter::options())->toBe([
        'credit' => 'دائن',
        'debit' => 'مدين',
    ]);
});
