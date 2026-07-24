<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Events\DatabaseNotificationsSentNow;
use Filament\Facades\Filament;
use Filament\Livewire\DatabaseNotifications;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

final class DatabaseNotificationsRefresh
{
    public static function pollingInterval(): string
    {
        return (string) config('fundflow.database_notifications_polling', '5s');
    }

    /**
     * Prefer Reverb/Echo for instant refresh; always keep light HTTP polling as a
     * fallback when the websocket proxy is unavailable.
     */
    public static function panelPollingInterval(): ?string
    {
        return self::pollingInterval();
    }

    public static function dispatch(?Component $livewire, Model|Authenticatable|null $notifiable = null): void
    {
        if ($notifiable !== null && Filament::hasBroadcasting() && config('filament.broadcasting.echo')) {
            DatabaseNotificationsSentNow::dispatch($notifiable);

            return;
        }

        if ($livewire === null) {
            return;
        }

        $targetName = json_encode(
            app('livewire.factory')->resolveComponentName(DatabaseNotifications::class),
            JSON_THROW_ON_ERROR,
        );

        $livewire->js(
            'setTimeout(() => window.Livewire.getByName('.$targetName.').forEach(w => w.$refresh()), 0)',
        );
    }
}
