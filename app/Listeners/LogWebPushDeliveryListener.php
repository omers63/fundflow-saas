<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use NotificationChannels\WebPush\Events\NotificationFailed;
use NotificationChannels\WebPush\Events\NotificationSent;

class LogWebPushDeliveryListener
{
    public function handleSent(NotificationSent $event): void
    {
        Log::info('Web push notification sent.', [
            'endpoint' => $event->report->getEndpoint(),
            'subscription_id' => $event->subscription->getKey(),
        ]);
    }

    public function handleFailed(NotificationFailed $event): void
    {
        Log::warning('Web push notification failed.', [
            'endpoint' => $event->report->getEndpoint(),
            'subscription_id' => $event->subscription->getKey(),
            'reason' => $event->report->getReason(),
            'expired' => $event->report->isSubscriptionExpired(),
        ]);
    }
}
