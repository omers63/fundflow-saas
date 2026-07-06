<?php

declare(strict_types=1);

use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Support\MemberNotificationChannels;
use App\Support\MemberUserEmail;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    $this->resolver = app(MemberUserEmail::class);
});

test('deliverable email resolves household contact for internal login users', function () {
    $user = User::create([
        'name' => 'Household Child',
        'email' => $this->resolver->generateInternalLoginEmail(),
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    Member::create([
        'user_id' => $user->id,
        'member_number' => 'MEM-INT-01',
        'name' => 'Household Child',
        'email' => 'family@example.test',
        'household_email' => 'family@example.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    expect($this->resolver->deliverableEmailFor($user->fresh('member')))
        ->toBe('family@example.test')
        ->and($user->routeNotificationForMail())
        ->toBe('family@example.test');
});

test('deliverable email uses user email for separated members', function () {
    $user = User::create([
        'name' => 'Separated Child',
        'email' => 'child@example.test',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    Member::create([
        'user_id' => $user->id,
        'member_number' => 'MEM-SEP-01',
        'name' => 'Separated Child',
        'email' => 'child@example.test',
        'household_email' => 'family@example.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
        'is_separated' => true,
        'direct_login_enabled' => true,
    ]);

    expect($this->resolver->deliverableEmailFor($user->fresh('member')))
        ->toBe('child@example.test');
});

test('member notification channels skip mail when only internal login email exists', function () {
    $user = User::create([
        'name' => 'Orphan Internal',
        'email' => $this->resolver->generateInternalLoginEmail(),
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $channels = MemberNotificationChannels::resolve($user);

    expect($channels)->not->toContain('mail');
});

test('member notification channels include mail when household contact is deliverable', function () {
    $user = User::create([
        'name' => 'Household Child',
        'email' => $this->resolver->generateInternalLoginEmail(),
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    Member::create([
        'user_id' => $user->id,
        'member_number' => 'MEM-INT-02',
        'name' => 'Household Child',
        'email' => 'family@example.test',
        'household_email' => 'family@example.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    $channels = MemberNotificationChannels::resolve($user->fresh('member'));

    expect($channels)->toContain('mail');
});
