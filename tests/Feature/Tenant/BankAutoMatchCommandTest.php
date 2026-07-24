<?php

declare(strict_types=1);

use App\Support\BatchPostingGate;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

test('bank auto-match soft-skips when batch posting is halted', function () {
    app(BatchPostingGate::class)->halt('Test halt');

    $exit = Artisan::call('bank:auto-match', ['--tenants' => ['testing'], '--force' => true]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('Test halt');
});

test('bank auto-match succeeds when batch posting is allowed', function () {
    app(BatchPostingGate::class)->clear();

    $exit = Artisan::call('bank:auto-match', ['--tenants' => ['testing'], '--force' => true]);

    expect($exit)->toBe(0);
});
