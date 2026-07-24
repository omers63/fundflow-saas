<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use NotificationChannels\WebPush\Events\NotificationFailed;
use NotificationChannels\WebPush\Events\NotificationSent;
use Throwable;

class LogWebPushDeliveryListener
{
    public function handleSent(NotificationSent $event): void
    {
        $this->safeLog(fn () => Log::info('Web push notification sent.', [
            'endpoint' => $event->report->getEndpoint(),
            'subscription_id' => $event->subscription->getKey(),
        ]));
    }

    public function handleFailed(NotificationFailed $event): void
    {
        $this->safeLog(fn () => Log::warning('Web push notification failed.', [
            'endpoint' => $event->report->getEndpoint(),
            'subscription_id' => $event->subscription->getKey(),
            'reason' => $event->report->getReason(),
            'expired' => $event->report->isSubscriptionExpired(),
        ]));
    }

    /**
     * @param  callable(): void  $log
     */
    private function safeLog(callable $log): void
    {
        try {
            $log();
        } catch (Throwable) {
            // Delivery already succeeded/failed; logging must not fail the HTTP request.
        }
    }
}
