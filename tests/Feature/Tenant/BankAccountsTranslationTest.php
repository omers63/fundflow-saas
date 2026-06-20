<?php

declare(strict_types=1);

use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;

test('bank accounts user-facing strings have arabic translations', function () {
    $output = shell_exec('php '.base_path('scripts/find-bank-accounts-missing-ar.php'));

    expect($output)->not->toBeNull();

    preg_match('/Total missing: (\d+)/', (string) $output, $matches);

    expect((int) ($matches[1] ?? -1))->toBe(0);
});

test('bank statement and transaction status options translate in arabic locale', function () {
    app()->setLocale('ar');

    expect(BankStatement::statusOptions()['completed'])->toBe('مكتمل')
        ->and(BankTransaction::statusOptions()['imported'])->toBe('مستورد')
        ->and(BankTransaction::statusOptions()['posted'])->toBe('مُرحّل');
});
