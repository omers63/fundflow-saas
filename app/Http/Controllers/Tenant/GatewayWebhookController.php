<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Gateway\GatewayAdapterResolver;
use App\Services\Gateway\GatewayPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

final class GatewayWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $provider,
        GatewayAdapterResolver $resolver,
        GatewayPaymentService $payments,
    ): JsonResponse {
        try {
            $adapter = $resolver->driver($provider);
            $event = $adapter->verifyWebhook($request);
            $payment = $payments->handleWebhookEvent($event, $adapter);

            return response()->json([
                'ok' => true,
                'payment_id' => $payment->id,
                'status' => $payment->status,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'message' => __('Webhook processing failed.'),
            ], 500);
        }
    }
}
