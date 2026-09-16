<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Contracts\PaymentGatewayAdapter;
use App\Models\Tenant\GatewayPayment;
use App\Support\Gateway\GatewayCheckout;
use App\Support\Gateway\GatewayWebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class FakeGatewayAdapter implements PaymentGatewayAdapter
{
    public function driver(): string
    {
        return GatewayPayment::PROVIDER_FAKE;
    }

    public function createPayment(GatewayPayment $payment): GatewayCheckout
    {
        $ref = 'fake_'.Str::lower(Str::ulid()->toBase32());

        return new GatewayCheckout(
            providerRef: $ref,
            checkoutUrl: null,
            clientSecret: null,
            metadata: ['fake' => true],
        );
    }

    public function verifyWebhook(Request $request): GatewayWebhookEvent
    {
        $secret = (string) config('gateway.fake.webhook_secret');
        $provided = (string) $request->header('X-Gateway-Signature', '');

        if ($secret === '' || ! hash_equals($secret, $provided)) {
            throw new InvalidArgumentException(__('Invalid gateway webhook signature.'));
        }

        $providerRef = (string) $request->input('provider_ref', '');
        $status = (string) $request->input('status', 'paid');

        if ($providerRef === '') {
            throw new InvalidArgumentException(__('Missing provider reference.'));
        }

        return new GatewayWebhookEvent(
            providerRef: $providerRef,
            status: in_array($status, ['paid', 'failed', 'cancelled'], true) ? $status : 'failed',
            amount: $request->filled('amount') ? (float) $request->input('amount') : null,
            payload: $request->all(),
        );
    }

    public function parseProviderRef(Request $request): ?string
    {
        $ref = (string) $request->input('provider_ref', '');

        return $ref !== '' ? $ref : null;
    }
}
