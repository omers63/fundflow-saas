<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use Tests\TestCase;

uses(TestCase::class);

test('master suspense account uses master suspense display label', function (): void {
    $account = new Account([
        'type' => 'suspense',
        'name' => 'Reconciliation suspense',
        'is_master' => true,
    ]);

    expect($account->displayLabel())->toBe('Master Suspense');
});

test('other accounts use stored name for display label', function (): void {
    $account = new Account([
        'type' => 'fund',
        'name' => 'Master Fund',
        'is_master' => true,
    ]);

    expect($account->displayLabel())->toBe('Master Fund');
});
