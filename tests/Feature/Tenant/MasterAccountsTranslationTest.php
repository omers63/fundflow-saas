<?php

declare(strict_types=1);

use App\Models\Tenant\Account;

test('master accounts user-facing strings have arabic translations', function () {
    $output = shell_exec('php '.base_path('scripts/find-master-accounts-missing-ar.php'));

    expect($output)->not->toBeNull();

    preg_match('/Total missing: (\d+)/', (string) $output, $matches);

    expect((int) ($matches[1] ?? -1))->toBe(0);
});

test('master account display labels translate in arabic locale', function () {
    app()->setLocale('ar');

    $cash = Account::factory()->make([
        'is_master' => true,
        'type' => 'cash',
        'name' => 'Master Cash',
    ]);

    expect($cash->displayLabel())->toBe('النقد الرئيسي');
});
