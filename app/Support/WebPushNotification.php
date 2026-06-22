<?php

declare(strict_types=1);

namespace App\Support;

final class WebPushNotification
{
    public static function enabled(): bool
    {
        return filled(config('webpush.vapid.public_key'))
            && filled(config('webpush.vapid.private_key'));
    }
}
