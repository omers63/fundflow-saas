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

final class MoyasarAdapter implements PaymentGatewayAdapter
{
    public function driver(): string
    {
        return GatewayPayment::PROVIDER_MOYASAR;
    }

    public function createPayment(GatewayPayment $payment): GatewayCheckout
    {
        $secret = (string) config('gateway.moyasar.secret_key');

        if ($secret === '') {
            throw new RuntimeException(__('Moyasar secret key is not configured.'));
        }

        $amountHalalas = (int) round(((float) $payment->amount) * 100);

        $response = Http::withBasicAuth($secret, '')
            ->acceptJson()
            ->post(rtrim((string) config('gateway.moyasar.base_url'), '/').'/invoices', [
                'amount' => $amountHalalas,
                'currency' => $payment->currency,
                'description' => sprintf('Gateway payment #%d (%s)', $payment->id, $payment->purpose),
                'metadata' => [
                    'gateway_payment_id' => $payment->id,
                    'member_id' => $payment->member_id,
                    'purpose' => $payment->purpose,
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(__('Moyasar invoice creation failed: :body', [
                'body' => $response->body(),
            ]));
        }

        $data = $response->json();
        $ref = (string) ($data['id'] ?? '');

        if ($ref === '') {
            throw new RuntimeException(__('Moyasar response missing invoice id.'));
        }

        return new GatewayCheckout(
            providerRef: $ref,
            checkoutUrl: $data['url'] ?? null,
            clientSecret: null,
            metadata: is_array($data) ? $data : [],
        );
    }

    public function verifyWebhook(Request $request): GatewayWebhookEvent
    {
        $secret = (string) config('gateway.moyasar.webhook_secret');
        $signature = (string) $request->header('X-Moyasar-Signature', $request->header('X-Webhook-Secret', ''));

        if ($secret === '' || ! hash_equals($secret, $signature)) {
            throw new InvalidArgumentException(__('Invalid Moyasar webhook signature.'));
        }

        $payload = $request->all();
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $providerRef = (string) ($data['id'] ?? $request->input('id', ''));

        if ($providerRef === '') {
            throw new InvalidArgumentException(__('Missing Moyasar payment id.'));
        }

        $rawStatus = strtolower((string) ($data['status'] ?? $payload['type'] ?? 'failed'));
        $status = match (true) {
            in_array($rawStatus, ['paid', 'payment_paid', 'captured'], true) => 'paid',
            in_array($rawStatus, ['failed', 'payment_failed'], true) => 'failed',
            in_array($rawStatus, ['canceled', 'cancelled', 'payment_canceled'], true) => 'cancelled',
            default => 'failed',
        };

        $amount = null;
        if (isset($data['amount'])) {
            $amount = ((float) $data['amount']) / 100;
        }

        return new GatewayWebhookEvent(
            providerRef: $providerRef,
            status: $status,
            amount: $amount,
            payload: $payload,
        );
    }

    public function parseProviderRef(Request $request): ?string
    {
        $payload = $request->all();
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $ref = (string) ($data['id'] ?? $request->input('id', ''));

        return $ref !== '' ? $ref : null;
    }
}
