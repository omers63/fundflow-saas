<?php

declare(strict_types=1);

namespace App\Events;

use Filament\Notifications\Events\DatabaseNotificationsSent;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Immediate websocket push for the Filament notification bell (no queue delay).
 */
class DatabaseNotificationsSentNow extends DatabaseNotificationsSent implements ShouldBroadcastNow {}
