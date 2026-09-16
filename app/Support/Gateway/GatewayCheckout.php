<?php

declare(strict_types=1);

namespace App\Support\Gateway;

final readonly class GatewayCheckout
{
    public function __construct(
        public string $providerRef,
        public ?string $checkoutUrl = null,
        public ?string $clientSecret = null,
        public array $metadata = [],
    ) {}
}
