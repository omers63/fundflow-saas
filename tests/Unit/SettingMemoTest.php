<?php

declare(strict_types=1);

use App\Models\Tenant\Setting;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Setting::query()->where('group', 'memo_test')->delete();
});

test('settings reads hit the database once until a write', function () {
    Setting::set('memo_test', 'a', '1');
    Setting::flushMemo();

    $queries = 0;
    DB::connection('tenant')->listen(function () use (&$queries): void {
        $queries++;
    });

    Setting::get('memo_test', 'a');
    Setting::get('memo_test', 'a');
    Setting::get('memo_test', 'missing', 'x');
    Setting::getGroup('memo_test');

    expect($queries)->toBe(1);
});

test('settings memo is invalidated by model and bulk writes', function () {
    Setting::set('memo_test', 'a', '1');
    expect(Setting::get('memo_test', 'a'))->toBe('1');

    Setting::set('memo_test', 'a', '2');
    expect(Setting::get('memo_test', 'a'))->toBe('2');

    Setting::query()->where('group', 'memo_test')->update(['value' => '3']);
    expect(Setting::get('memo_test', 'a'))->toBe('3');

    Setting::query()->where('group', 'memo_test')->delete();
    expect(Setting::get('memo_test', 'a', 'gone'))->toBe('gone')
        ->and(Setting::getGroup('memo_test'))->toBe([]);
});
