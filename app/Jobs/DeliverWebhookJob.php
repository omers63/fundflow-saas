<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant\WebhookDelivery;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $deliveryId,
    ) {}

    public function handle(WebhookDispatcher $dispatcher): void
    {
        $delivery = WebhookDelivery::query()->find($this->deliveryId);

        if ($delivery === null || $delivery->isDelivered()) {
            return;
        }

        $dispatcher->deliver($delivery);
    }
}
