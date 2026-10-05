<?php

declare(strict_types=1);

use App\Models\Tenant\User;
use App\Support\StepUpGuard;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Config::set('auth.step_up_ttl_minutes', 15);
    User::query()->delete();

    $this->user = User::create([
        'name' => 'Step Up Admin',
        'email' => 'step-up@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $this->guard = app(StepUpGuard::class);
    $this->guard->clear();
});

test('approve without step-up fails', function () {
    expect($this->guard->isConfirmed())->toBeFalse();
    $this->guard->assertConfirmed();
})->throws(InvalidArgumentException::class);

test('confirm password unlocks step-up window', function () {
    $this->guard->confirm($this->user, 'password');

    expect($this->guard->isConfirmed())->toBeTrue();
    $this->guard->assertConfirmed();
});

test('wrong password does not unlock step-up', function () {
    $this->guard->confirm($this->user, 'wrong-password');
})->throws(InvalidArgumentException::class);
