<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Contracts\PaymentGatewayAdapter;
use App\Models\Tenant\GatewayPayment;
use App\Support\Gateway\GatewayCheckout;
use App\Support\Gateway\GatewayWebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

final class HyperPayAdapter implements PaymentGatewayAdapter
{
    public function driver(): string
    {
        return GatewayPayment::PROVIDER_HYPERPAY;
    }

    public function createPayment(GatewayPayment $payment): GatewayCheckout
    {
        $entityId = (string) config('gateway.hyperpay.entity_id');
        $token = (string) config('gateway.hyperpay.access_token');

        if ($entityId === '' || $token === '') {
            throw new RuntimeException(__('HyperPay credentials are not configured.'));
        }

        $amount = number_format((float) $payment->amount, 2, '.', '');

        $response = Http::withToken($token)
            ->asForm()
            ->post(rtrim((string) config('gateway.hyperpay.base_url'), '/').'/v1/checkouts', [
                'entityId' => $entityId,
                'amount' => $amount,
                'currency' => $payment->currency,
                'paymentType' => 'DB',
                'merchantTransactionId' => (string) $payment->id,
                'customer.email' => $payment->member?->email,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(__('HyperPay checkout creation failed: :body', [
                'body' => $response->body(),
            ]));
        }

        $data = $response->json();
        $ref = (string) ($data['id'] ?? '');

        if ($ref === '') {
            throw new RuntimeException(__('HyperPay response missing checkout id.'));
        }

        $base = rtrim((string) config('gateway.hyperpay.base_url'), '/');

        return new GatewayCheckout(
            providerRef: $ref,
            checkoutUrl: $base.'/v1/paymentWidgets.js?checkoutId='.$ref,
            clientSecret: $ref,
            metadata: is_array($data) ? $data : [],
        );
    }

    public function verifyWebhook(Request $request): GatewayWebhookEvent
    {
        $secret = (string) config('gateway.hyperpay.webhook_secret');
        $signature = (string) $request->header('X-Hyperpay-Signature', $request->header('X-Initialization-Vector', ''));

        // Sandbox / test: accept shared secret header when configured.
        if ($secret !== '' && $signature !== '' && ! hash_equals($secret, $signature)) {
            // Also try HMAC of raw body when provided as hex digest.
            $raw = $request->getContent();
            $hmac = hash_hmac('sha256', $raw, $secret);
            if (! hash_equals($hmac, $signature)) {
                throw new InvalidArgumentException(__('Invalid HyperPay webhook signature.'));
            }
        } elseif ($secret !== '' && $signature === '') {
            throw new InvalidArgumentException(__('Invalid HyperPay webhook signature.'));
        }

        $payload = $request->all();
        $providerRef = (string) (
            $payload['id']
            ?? data_get($payload, 'payload.id')
            ?? data_get($payload, 'resourcePath')
            ?? ''
        );

        if ($providerRef === '' && isset($payload['resourcePath'])) {
            $providerRef = basename((string) $payload['resourcePath']);
        }

        if ($providerRef === '') {
            throw new InvalidArgumentException(__('Missing HyperPay payment id.'));
        }

        $code = (string) data_get($payload, 'result.code', data_get($payload, 'payload.result.code', ''));
        $status = match (true) {
            str_starts_with($code, '000.000.') || str_starts_with($code, '000.100.') => 'paid',
            str_starts_with($code, '000.200.') => 'pending',
            default => 'failed',
        };

        $amount = data_get($payload, 'amount', data_get($payload, 'payload.amount'));

        return new GatewayWebhookEvent(
            providerRef: $providerRef,
            status: $status === 'pending' ? 'failed' : $status,
            amount: $amount !== null ? (float) $amount : null,
            payload: $payload,
        );
    }

    public function parseProviderRef(Request $request): ?string
    {
        $payload = $request->all();
        $ref = (string) (
            $payload['id']
            ?? data_get($payload, 'payload.id')
            ?? ''
        );

        if ($ref === '' && isset($payload['resourcePath'])) {
            $ref = basename((string) $payload['resourcePath']);
        }

        return $ref !== '' ? $ref : null;
    }
}
