<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Contracts\PaymentGatewayAdapter;
use App\Models\Tenant\GatewayPayment;
use InvalidArgumentException;

final class GatewayAdapterResolver
{
    public function driver(?string $driver = null): PaymentGatewayAdapter
    {
        $driver ??= (string) config('gateway.driver', GatewayPayment::PROVIDER_FAKE);

        return match ($driver) {
            GatewayPayment::PROVIDER_FAKE => app(FakeGatewayAdapter::class),
            GatewayPayment::PROVIDER_MOYASAR => app(MoyasarAdapter::class),
            GatewayPayment::PROVIDER_HYPERPAY => app(HyperPayAdapter::class),
            GatewayPayment::PROVIDER_SADAD => app(SadadGatewayAdapter::class),
            default => throw new InvalidArgumentException(__('Unsupported payment gateway driver: :driver', [
                'driver' => $driver,
            ])),
        };
    }
}
