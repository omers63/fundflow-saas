<?php

declare(strict_types=1);

use App\Models\Tenant\User;
use App\Support\MemberNotificationLocale;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    MemberNotificationLocale::reset();
});

it('enters and leaves member locale with reference counting', function (): void {
    app()->setLocale('en');

    $user = new User([
        'preferred_locale' => 'ar',
    ]);

    MemberNotificationLocale::enter($user);
    expect(app()->getLocale())->toBe('ar');

    MemberNotificationLocale::leave();
    expect(app()->getLocale())->toBe('en');
});
