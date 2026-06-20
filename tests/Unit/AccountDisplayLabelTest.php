<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use Database\Seeders\Tenant\TenantDatabaseSeeder;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

test('master suspense account uses master suspense display label', function (): void {
    $account = new Account([
        'type' => 'suspense',
        'name' => 'Reconciliation suspense',
        'is_master' => true,
    ]);

    expect($account->displayLabel())->toBe(__('Master Suspense'));
});

test('tenant seeder provisions master suspense with other master accounts', function (): void {
    $this->initializeTenancy();

    Account::query()->delete();

    $this->seed(TenantDatabaseSeeder::class);

    $types = Account::query()->master()->pluck('type')->sort()->values()->all();

    expect($types)->toBe(['bank', 'cash', 'expense', 'fees', 'fund', 'invest', 'suspense'])
        ->and(Account::masterSuspense()?->name)->toBe('Master Suspense');
});

test('other master accounts use translated display labels', function (): void {
    app()->setLocale('en');

    $account = new Account([
        'type' => 'fund',
        'name' => 'Master Fund',
        'is_master' => true,
    ]);

    expect($account->displayLabel())->toBe(__('Master Fund'));
});
