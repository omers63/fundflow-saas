<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Jobs\DeliverWebhookJob;
use App\Models\Tenant\WebhookDelivery;
use App\Models\Tenant\WebhookEndpoint;
use App\Support\Billing\TenantFeatureGate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class WebhookDispatcher
{
    public function __construct(
        private readonly TenantFeatureGate $features,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(string $event, array $payload = []): int
    {
        if (! $this->features->allows(TenantFeatureGate::FEATURE_WEBHOOKS)) {
            return 0;
        }

        $body = [
            'id' => (string) Str::uuid(),
            'event' => $event,
            'occurred_at' => now()->toIso8601String(),
            'tenant_id' => tenant('id'),
            'data' => $payload,
        ];

        $queued = 0;

        WebhookEndpoint::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->each(function (WebhookEndpoint $endpoint) use ($event, $body, &$queued): void {
                if (! $endpoint->listensFor($event)) {
                    return;
                }

                $json = json_encode($body, JSON_THROW_ON_ERROR);
                $signature = hash_hmac('sha256', $json, $endpoint->secret);

                $delivery = WebhookDelivery::query()->create([
                    'webhook_endpoint_id' => $endpoint->id,
                    'event' => $event,
                    'payload' => $body,
                    'status' => WebhookDelivery::STATUS_PENDING,
                    'attempt' => 0,
                    'signature' => $signature,
                    'next_retry_at' => now(),
                ]);

                DeliverWebhookJob::dispatch($delivery->id);
                $queued++;
            });

        return $queued;
    }

    public function deliver(WebhookDelivery $delivery): WebhookDelivery
    {
        $endpoint = $delivery->endpoint;

        if ($endpoint === null || ! $endpoint->is_active) {
            $delivery->forceFill([
                'status' => WebhookDelivery::STATUS_FAILED,
                'error' => 'Endpoint missing or inactive',
            ])->save();

            return $delivery->fresh();
        }

        $json = json_encode($delivery->payload, JSON_THROW_ON_ERROR);
        $signature = $delivery->signature ?: hash_hmac('sha256', $json, $endpoint->secret);
        $attempt = (int) $delivery->attempt + 1;

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-FundFlow-Event' => $delivery->event,
                    'X-FundFlow-Signature' => $signature,
                    'X-FundFlow-Delivery' => (string) $delivery->id,
                ])
                ->withBody($json, 'application/json')
                ->post($endpoint->url);

            if ($response->successful()) {
                $delivery->forceFill([
                    'status' => WebhookDelivery::STATUS_DELIVERED,
                    'attempt' => $attempt,
                    'http_status' => $response->status(),
                    'response_body' => Str::limit($response->body(), 2000),
                    'error' => null,
                    'signature' => $signature,
                    'delivered_at' => now(),
                    'next_retry_at' => null,
                ])->save();

                return $delivery->fresh();
            }

            $this->markFailedOrRetry($delivery, $attempt, $response->status(), Str::limit($response->body(), 2000), 'HTTP '.$response->status());
        } catch (\Throwable $e) {
            $this->markFailedOrRetry($delivery, $attempt, null, null, $e->getMessage());
        }

        return $delivery->fresh();
    }

    public static function verifySignature(string $rawBody, string $secret, string $signature): bool
    {
        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    private function markFailedOrRetry(
        WebhookDelivery $delivery,
        int $attempt,
        ?int $httpStatus,
        ?string $responseBody,
        string $error,
    ): void {
        $maxAttempts = 5;
        $backoffMinutes = [1, 5, 15, 60, 240];

        if ($attempt >= $maxAttempts) {
            $delivery->forceFill([
                'status' => WebhookDelivery::STATUS_FAILED,
                'attempt' => $attempt,
                'http_status' => $httpStatus,
                'response_body' => $responseBody,
                'error' => $error,
                'next_retry_at' => null,
            ])->save();

            return;
        }

        $delay = $backoffMinutes[min($attempt - 1, count($backoffMinutes) - 1)];

        $delivery->forceFill([
            'status' => WebhookDelivery::STATUS_PENDING,
            'attempt' => $attempt,
            'http_status' => $httpStatus,
            'response_body' => $responseBody,
            'error' => $error,
            'next_retry_at' => now()->addMinutes($delay),
        ])->save();

        DeliverWebhookJob::dispatch($delivery->id)->delay(now()->addMinutes($delay));
    }
}
