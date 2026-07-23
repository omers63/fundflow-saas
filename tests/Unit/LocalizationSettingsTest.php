<?php

declare(strict_types=1);

use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Support\LocalizationSettings;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Setting::query()->where('group', LocalizationSettings::GROUP)->delete();
});

test('localization settings default to english for admins and arabic for members', function () {
    expect(LocalizationSettings::adminLocale())->toBe('en')
        ->and(LocalizationSettings::memberLocale())->toBe('ar')
        ->and(LocalizationSettings::allForForm())->toBe([
            'localization_default_admin_locale' => 'en',
            'localization_default_member_locale' => 'ar',
        ]);
});

test('localization settings can be saved from settings form state', function () {
    LocalizationSettings::saveFromForm([
        'localization_default_admin_locale' => 'en',
        'localization_default_member_locale' => 'en',
    ]);

    expect(LocalizationSettings::adminLocale())->toBe('en')
        ->and(LocalizationSettings::memberLocale())->toBe('en')
        ->and(Setting::get(LocalizationSettings::GROUP, 'default_admin_locale'))->toBe('en')
        ->and(Setting::get(LocalizationSettings::GROUP, 'default_member_locale'))->toBe('en');
});

test('user preferred locale falls back to tenant defaults when unset', function () {
    LocalizationSettings::saveFromForm([
        'localization_default_admin_locale' => 'en',
        'localization_default_member_locale' => 'ar',
    ]);

    $admin = User::create([
        'name' => 'Admin Locale',
        'email' => 'admin-locale-'.uniqid('', true).'@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
        'preferred_locale' => null,
    ]);

    $member = User::create([
        'name' => 'Member Locale',
        'email' => 'member-locale-'.uniqid('', true).'@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
        'preferred_locale' => null,
    ]);

    expect($admin->preferredLocale())->toBe('en')
        ->and($member->preferredLocale())->toBe('ar');
});

test('user preferred locale uses personal choice when set', function () {
    LocalizationSettings::saveFromForm([
        'localization_default_admin_locale' => 'ar',
        'localization_default_member_locale' => 'ar',
    ]);

    $member = User::create([
        'name' => 'Member Locale Choice',
        'email' => 'member-locale-choice-'.uniqid('', true).'@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
        'preferred_locale' => 'en',
    ]);

    expect($member->preferredLocale())->toBe('en');
});

test('guest locale resolves admin and member paths', function () {
    LocalizationSettings::saveFromForm([
        'localization_default_admin_locale' => 'en',
        'localization_default_member_locale' => 'ar',
    ]);

    expect(LocalizationSettings::guestLocale(
        request()->create('/admin/login', 'GET')
    ))->toBe('en')
        ->and(LocalizationSettings::guestLocale(
            request()->create('/member/login', 'GET')
        ))->toBe('ar')
        ->and(LocalizationSettings::guestLocale(
            request()->create('/', 'GET')
        ))->toBe('ar');
});
