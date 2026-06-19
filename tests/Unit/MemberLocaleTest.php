<?php

declare(strict_types=1);

use App\Models\Tenant\User;
use App\Support\MemberLocale;
use Tests\TestCase;

uses(TestCase::class);

it('prefers active request locale over member preferred locale', function (): void {
    app()->setLocale('en');

    $user = new User([
        'preferred_locale' => 'ar',
    ]);

    expect(MemberLocale::forRequest($user))->toBe('en');
});

it('falls back to member preferred locale when request locale is unsupported', function (): void {
    app()->setLocale('fr');

    $user = new User([
        'preferred_locale' => 'ar',
    ]);

    expect(MemberLocale::forRequest($user))->toBe('ar');
});

it('runs callback in resolved locale and restores previous locale', function (): void {
    app()->setLocale('fr');

    $user = new User([
        'preferred_locale' => 'ar',
    ]);

    $result = MemberLocale::using($user, function (): string {
        return app()->getLocale().'|'.__('SAR');
    });

    expect($result)->toBe('ar|'."\u{20C1}")
        ->and(app()->getLocale())->toBe('fr');
});

it('restores locale when callback throws', function (): void {
    app()->setLocale('en');

    $user = new User([
        'preferred_locale' => 'ar',
    ]);

    try {
        MemberLocale::using($user, function (): void {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(app()->getLocale())->toBe('en');
});
