<?php

declare(strict_types=1);

use App\Notifications\Tenant\TestAdminWebPushNotification;
use App\Support\WebPushNotification;

test('web push notification assets use transparent badge and icon paths', function () {
    expect(WebPushNotification::BADGE_PATH)->toBe('/icons/notification-badge-96x96.png')
        ->and(WebPushNotification::ICON_PATH)->toBe('/icons/notification-icon-192x192.png')
        ->and(file_exists(public_path('icons/notification-badge-96x96.png')))->toBeTrue()
        ->and(file_exists(public_path('icons/notification-icon-192x192.png')))->toBeTrue()
        ->and(WebPushNotification::badgeUrl())->toEndWith(WebPushNotification::BADGE_PATH)
        ->and(WebPushNotification::iconUrl())->toEndWith(WebPushNotification::ICON_PATH)
        ->and(str_starts_with(WebPushNotification::badgeUrl(), 'http'))->toBeTrue();
});

test('admin web push message references dedicated notification icons', function () {
    $notification = new TestAdminWebPushNotification;
    $admin = new stdClass;
    $payload = $notification->toWebPush($admin, $notification)->toArray();

    expect($payload['icon'])->toBe(WebPushNotification::iconUrl())
        ->and($payload['badge'])->toBe(WebPushNotification::badgeUrl());
});
