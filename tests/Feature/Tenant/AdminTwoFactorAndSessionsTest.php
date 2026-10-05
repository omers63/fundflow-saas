<?php

declare(strict_types=1);

use App\Models\Tenant\User;
use App\Services\Security\AdminSessionService;
use App\Services\Security\TwoFactorService;
use App\Support\Security\SecuritySettings;
use App\Support\Security\TotpService;
use App\Support\Security\TwoFactorSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    User::query()->delete();
    SecuritySettings::setEnforceAdminTwoFactor(false);
    TwoFactorSession::clear();

    $this->admin = User::create([
        'name' => 'Sec Admin',
        'email' => 'sec-admin@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);
});

test('admin can enroll and confirm totp then verify login code', function () {
    $service = app(TwoFactorService::class);
    $started = $service->beginEnrollment($this->admin);

    expect($this->admin->fresh()->hasTwoFactorEnabled())->toBeFalse()
        ->and($started['secret'])->not->toBeEmpty()
        ->and($started['recovery_codes'])->toHaveCount(8);

    $code = app(TotpService::class)->at($started['secret'], (int) floor(time() / 30));
    $service->confirmEnrollment($this->admin->fresh(), $code);

    expect($this->admin->fresh()->hasTwoFactorEnabled())->toBeTrue();

    $loginCode = app(TotpService::class)->at(
        (string) $this->admin->fresh()->two_factor_secret,
        (int) floor(time() / 30),
    );

    expect($service->verifyLoginCode($this->admin->fresh(), $loginCode))->toBeTrue();
});

test('enforced policy flag is readable and session revoke skips current', function () {
    SecuritySettings::setEnforceAdminTwoFactor(true);
    expect(SecuritySettings::enforceAdminTwoFactor())->toBeTrue();

    if (! Schema::hasTable('sessions')) {
        $this->markTestSkipped('sessions table missing');
    }

    $currentId = session()->getId();
    DB::table('sessions')->insert([
        'id' => $currentId,
        'user_id' => $this->admin->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'PHPUnit',
        'payload' => '',
        'last_activity' => time(),
    ]);
    DB::table('sessions')->insert([
        'id' => 'other-session-id',
        'user_id' => $this->admin->id,
        'ip_address' => '10.0.0.2',
        'user_agent' => 'Other',
        'payload' => '',
        'last_activity' => time() - 60,
    ]);

    $sessions = app(AdminSessionService::class);
    expect($sessions->listForUser((int) $this->admin->id))->toHaveCount(2)
        ->and($sessions->revoke('other-session-id', (int) $this->admin->id))->toBeTrue()
        ->and($sessions->revoke($currentId, (int) $this->admin->id))->toBeFalse()
        ->and($sessions->listForUser((int) $this->admin->id))->toHaveCount(1);
});
