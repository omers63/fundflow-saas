wh<?php

use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Support\MemberNumberSettings;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Setting::query()->where('group', MemberNumberSettings::GROUP)->delete();
    Member::query()->delete();
});

it('generates default formatted member numbers', function () {
    expect(MemberNumberSettings::generate())->toBe('MEM-0001');

    Member::create([
        'member_number' => MemberNumberSettings::generate(),
        'name' => 'First',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    expect(MemberNumberSettings::generate())->toBe('MEM-0002');
});

it('supports prefix year and custom padding', function () {
    MemberNumberSettings::save([
        'prefix' => 'FUND',
        'separator' => MemberNumberSettings::SEPARATOR_SLASH,
        'padding' => 3,
        'include_year' => true,
    ]);

    $year = now()->format('Y');

    expect(MemberNumberSettings::generate())->toBe("FUND/{$year}/001");
});

it('increments from highest matching sequence not member count', function () {
    Member::create([
        'member_number' => 'MEM-0099',
        'name' => 'Legacy',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    expect(MemberNumberSettings::generate())->toBe('MEM-0100');
});

it('restarts sequence each calendar year when year is included', function () {
    MemberNumberSettings::save([
        'prefix' => 'MEM',
        'separator' => MemberNumberSettings::SEPARATOR_HYPHEN,
        'padding' => 4,
        'include_year' => true,
    ]);

    $lastYear = now()->subYear()->format('Y');

    Member::create([
        'member_number' => "MEM-{$lastYear}-9999",
        'name' => 'Old',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    expect(MemberNumberSettings::generate())->toBe('MEM-'.now()->format('Y').'-0001');
});

it('previews using draft settings from the form', function () {
    Member::create([
        'member_number' => 'MEM-0005',
        'name' => 'Existing',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    expect(MemberNumberSettings::preview([
        'prefix' => 'X',
        'separator' => MemberNumberSettings::SEPARATOR_NONE,
        'padding' => 3,
        'include_year' => false,
    ]))->toBe('X001');
});

it('generates simple sequential member numbers', function () {
    MemberNumberSettings::save([
        'format' => MemberNumberSettings::FORMAT_SEQUENTIAL,
    ]);

    expect(MemberNumberSettings::generate())->toBe('1');

    Member::create([
        'member_number' => MemberNumberSettings::generate(),
        'name' => 'First',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    expect(MemberNumberSettings::generate())->toBe('2');
});

it('increments sequential numbers from highest numeric member number', function () {
    MemberNumberSettings::save([
        'format' => MemberNumberSettings::FORMAT_SEQUENTIAL,
    ]);

    Member::create([
        'member_number' => '88',
        'name' => 'Legacy',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    expect(MemberNumberSettings::generate())->toBe('89');
});

it('ignores formatted member numbers when using sequential format', function () {
    MemberNumberSettings::save([
        'format' => MemberNumberSettings::FORMAT_SEQUENTIAL,
    ]);

    Member::create([
        'member_number' => 'MEM-0099',
        'name' => 'Formatted',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    expect(MemberNumberSettings::generate())->toBe('1');
});

it('previews sequential numbers using draft settings', function () {
    Member::create([
        'member_number' => '12',
        'name' => 'Existing',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    expect(MemberNumberSettings::preview([
        'format' => MemberNumberSettings::FORMAT_SEQUENTIAL,
    ]))->toBe('13');
});

it('preserves formatted settings when saving sequential format only', function () {
    MemberNumberSettings::save([
        'prefix' => 'FUND',
        'separator' => MemberNumberSettings::SEPARATOR_SLASH,
        'padding' => 5,
        'include_year' => true,
    ]);

    MemberNumberSettings::save([
        'format' => MemberNumberSettings::FORMAT_SEQUENTIAL,
    ]);

    expect(MemberNumberSettings::all())->toMatchArray([
        'format' => MemberNumberSettings::FORMAT_SEQUENTIAL,
        'prefix' => 'FUND',
        'separator' => MemberNumberSettings::SEPARATOR_SLASH,
        'padding' => 5,
        'include_year' => true,
    ]);
});
