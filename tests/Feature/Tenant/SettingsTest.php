<?php

use App\Models\Tenant\Setting;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Setting::query()->delete();
});

test('setting can be stored and retrieved', function () {
    Setting::set('general', 'currency', 'USD');

    expect(Setting::get('general', 'currency'))->toBe('USD');
});

test('setting returns default when not found', function () {
    expect(Setting::get('general', 'missing_key', 'fallback'))->toBe('fallback');
});

test('setting can be updated', function () {
    Setting::set('general', 'currency', 'USD');
    Setting::set('general', 'currency', 'EUR');

    expect(Setting::get('general', 'currency'))->toBe('EUR');
    expect(Setting::where('group', 'general')->where('key', 'currency')->count())->toBe(1);
});

test('getGroup returns all settings for a group', function () {
    Setting::set('general', 'currency', 'USD');
    Setting::set('general', 'fund_name', 'Test Fund');
    Setting::set('other', 'unrelated', 'value');

    $group = Setting::getGroup('general');

    expect($group)->toHaveCount(2)
        ->and($group['currency'])->toBe('USD')
        ->and($group['fund_name'])->toBe('Test Fund');
});
