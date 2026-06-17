<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Services\ReconciliationSuspenseService;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function (): void {
    $this->initializeTenancy();
    Account::query()->delete();
});

test('ensure suspense account creates master suspense account', function (): void {
    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);

    $suspense = app(ReconciliationSuspenseService::class)->ensureSuspenseAccount();

    expect($suspense->type)->toBe('suspense')
        ->and($suspense->is_master)->toBeTrue()
        ->and($suspense->name)->toBe('Master Suspense')
        ->and($suspense->displayLabel())->toBe(__('Master Suspense'))
        ->and((float) $suspense->balance)->toBe(0.0);
});
