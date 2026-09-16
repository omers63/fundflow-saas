<?php

declare(strict_types=1);

namespace App\Support\Gateway;

final readonly class GatewayWebhookEvent
{
    public function __construct(
        public string $providerRef,
        public string $status,
        public ?float $amount = null,
        public array $payload = [],
    ) {}

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
