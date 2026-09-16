<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Contracts\PaymentGatewayAdapter;
use App\Models\Tenant\GatewayPayment;
use App\Support\Gateway\GatewayCheckout;
use App\Support\Gateway\GatewayWebhookEvent;
use App\Support\SadadSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * SADAD biller path: intents are settled via payment-file import (not card checkout).
 */
final class SadadGatewayAdapter implements PaymentGatewayAdapter
{
    public function driver(): string
    {
        return GatewayPayment::PROVIDER_SADAD;
    }

    public function createPayment(GatewayPayment $payment): GatewayCheckout
    {
        if (!SadadSettings::enabled() || SadadSettings::registrationStatus() !== 'active') {
            throw new InvalidArgumentException(__('SADAD biller registration is not active.'));
        }

        $ref = 'sadad_' . Str::lower(Str::ulid()->toBase32());

        return new GatewayCheckout(
            providerRef: $ref,
            checkoutUrl: null,
            clientSecret: null,
            metadata: [
                'sadad' => true,
                'biller_id' => SadadSettings::billerId(),
                'merchant_code' => SadadSettings::merchantCode(),
                'settlement' => 'payment_file',
            ],
        );
    }

    public function verifyWebhook(Request $request): GatewayWebhookEvent
    {
        throw new InvalidArgumentException(__('SADAD settles via payment file import, not webhooks.'));
    }

    public function parseProviderRef(Request $request): ?string
    {
        $ref = (string) $request->input('provider_ref', '');

        return $ref !== '' ? $ref : null;
    }
}
