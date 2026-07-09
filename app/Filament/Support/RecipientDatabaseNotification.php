<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\User;
use App\Support\MemberLocale;
use Filament\Notifications\Notification;

final class RecipientDatabaseNotification
{
    /**
     * Store a Filament database notification in the recipient's preferred locale.
     *
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

    /**
     * @param  callable(Notification): void  $configure
     */
    public static function sendWithColor(User $user, callable $configure, string $color = 'info'): void
    {
        self::send($user, function (Notification $notification) use ($configure, $color): void {
            $configure($notification);

            match ($color) {
                'success' => $notification->success(),
                'warning' => $notification->warning(),
                'danger' => $notification->danger(),
                default => null,
            };
        });
    }
}
