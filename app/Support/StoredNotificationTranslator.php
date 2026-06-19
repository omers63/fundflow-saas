<?php

declare(strict_types=1);

namespace App\Support;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;

final class StoredNotificationTranslator
{
    public static function localize(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $translated = __($value);

        return $translated === $value ? $value : $translated;
    }

    public static function localizeFilamentNotification(Notification $notification): Notification
    {
        $title = $notification->getTitle();

        if (filled($title)) {
            $notification->title(self::localize($title));
        }

        $body = $notification->getBody();

        if (filled($body)) {
            $notification->body(self::localize($body));
        }

        $notification->actions(
            collect($notification->getActions())
                ->map(function (Action|ActionGroup $action): Action|ActionGroup {
                    if ($action instanceof ActionGroup) {
                        return $action;
                    }

                    $label = $action->getLabel();

                    if (filled($label)) {
                        $action->label(self::localize($label));
                    }

                    return $action;
                })
                ->all(),
        );

        return $notification;
    }
}
