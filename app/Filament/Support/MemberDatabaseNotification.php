<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\User;
use App\Support\MemberLocale;
use Filament\Notifications\Notification;

final class MemberDatabaseNotification
{
    /**
     * @param  callable(Notification): void  $configure
     */
    public static function send(User $user, callable $configure): void
    {
        MemberLocale::usingPreferred($user, function () use ($user, $configure): void {
            $notification = Notification::make();
            $configure($notification);
            $notification->sendToDatabase($user);
        });
    }
}
