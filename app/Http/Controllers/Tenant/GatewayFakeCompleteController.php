<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\GatewayPayment;
use App\Models\Tenant\Member;
use App\Services\Gateway\GatewayPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class GatewayFakeCompleteController extends Controller
{
    public function __invoke(
        Request $request,
        GatewayPayment $payment,
        GatewayPaymentService $payments,
    ): JsonResponse {
        $driver = (string) config('gateway.driver', 'fake');
        $env = (string) app()->environment();

        if (! in_array($env, ['local', 'testing'], true) && $driver !== 'fake') {
            throw new AccessDeniedHttpException(__('Fake gateway completion is disabled.'));
        }

        if ($driver !== 'fake' && ! in_array($env, ['local', 'testing'], true)) {
            throw new AccessDeniedHttpException(__('Fake gateway completion is disabled.'));
        }

        $user = $request->user('tenant');
        $member = Member::query()->where('user_id', $user?->id)->first();

        if ($member === null || (int) $payment->member_id !== (int) $member->id) {
            throw new AccessDeniedHttpException(__('You cannot complete this payment.'));
        }

        try {
            $updated = $payments->completeFake($payment);

            return response()->json([
                'ok' => true,
                'payment_id' => $updated->id,
                'status' => $updated->status,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
