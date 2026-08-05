<?php

declare(strict_types=1);

use App\Support\TenantRuntimeCache;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    TenantRuntimeCache::flushRequestMemo();
    Cache::flush();
});

it('scopes runtime cache keys by active locale', function (): void {
    app()->setLocale('ar');
    expect(TenantRuntimeCache::localizedKey('tenant_dashboard_core'))
        ->toBe('tenant_dashboard_core:locale:ar');

    app()->setLocale('en');
    expect(TenantRuntimeCache::localizedKey('tenant_dashboard_core'))
        ->toBe('tenant_dashboard_core:locale:en');
});

it('does not return a payload cached under another locale', function (): void {
    app()->setLocale('ar');
    TenantRuntimeCache::remember('demo:payload', 60, fn (): string => 'arabic');

    app()->setLocale('en');
    $english = TenantRuntimeCache::remember('demo:payload', 60, fn (): string => 'english');

    app()->setLocale('ar');
    $arabicAgain = TenantRuntimeCache::remember('demo:payload', 60, fn (): string => 'stale');

    expect($english)->toBe('english')
        ->and($arabicAgain)->toBe('arabic');
});

it('memoizes distinct keys independently within one request', function (): void {
    app()->setLocale('en');

    $core = TenantRuntimeCache::remember('key:a', 60, fn (): string => 'core');
    $details = TenantRuntimeCache::remember('key:b', 60, fn (): string => 'details');

    expect($core)->toBe('core')
        ->and($details)->toBe('details');
});

it('forgets all locale variants of a key', function (): void {
    app()->setLocale('ar');
    TenantRuntimeCache::remember('forget:me', 60, fn (): string => 'ar-value');

    app()->setLocale('en');
    TenantRuntimeCache::remember('forget:me', 60, fn (): string => 'en-value');

    TenantRuntimeCache::forget('forget:me');

    app()->setLocale('ar');
    expect(TenantRuntimeCache::remember('forget:me', 60, fn (): string => 'ar-fresh'))->toBe('ar-fresh');

    app()->setLocale('en');
    expect(TenantRuntimeCache::remember('forget:me', 60, fn (): string => 'en-fresh'))->toBe('en-fresh');
});
