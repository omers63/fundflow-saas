<?php

declare(strict_types=1);

use App\Notifications\Tenant\TestAdminWebPushNotification;
use App\Support\AppBrand;
use App\Support\WebPushNotification;

test('web push notification assets use transparent badge and icon paths', function () {
    expect(is_file(AppBrand::iconAbsolutePath('notification_badge')))->toBeTrue()
        ->and(is_file(AppBrand::iconAbsolutePath('notification_icon')))->toBeTrue()
        ->and(file_exists(public_path('icons/notification-badge-96x96.png')))->toBeTrue()
        ->and(file_exists(public_path('icons/notification-icon-192x192.png')))->toBeTrue()
        ->and(WebPushNotification::badgeUrl())->toContain(AppBrand::iconWebPath('notification_badge'))
        ->and(WebPushNotification::iconUrl())->toContain(AppBrand::iconWebPath('notification_icon'))
        ->and(str_starts_with(WebPushNotification::badgeUrl(), 'http'))->toBeTrue();
});

test('admin web push message references dedicated notification icons', function () {
    $notification = new TestAdminWebPushNotification;
    $admin = new stdClass;
    $payload = $notification->toWebPush($admin, $notification)->toArray();

    expect($payload['icon'])->toBe(WebPushNotification::iconUrl())
        ->and($payload['badge'])->toBe(WebPushNotification::badgeUrl());
});

test('web push truncate keeps payloads under the push size budget', function () {
    $long = str_repeat('م', 300);

    expect(mb_strlen(WebPushNotification::truncate($long, WebPushNotification::MAX_TITLE_CHARS)))
        ->toBe(WebPushNotification::MAX_TITLE_CHARS)
        ->and(WebPushNotification::truncate('short', 80))->toBe('short');
});
