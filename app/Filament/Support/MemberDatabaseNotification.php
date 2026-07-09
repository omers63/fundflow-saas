<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\User;
use Filament\Notifications\Notification;

final class MemberDatabaseNotification
{
    /**
     * @param  callable(Notification): void  $configure
     */
    public static function send(User $user, callable $configure): void
    {
        RecipientDatabaseNotification::send($user, $configure);
    }
}
