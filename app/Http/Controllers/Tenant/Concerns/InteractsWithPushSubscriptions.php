<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Concerns;

use App\Models\Tenant\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait InteractsWithPushSubscriptions
{
    /**
     * @return array{endpoint: string, keys: array{p256dh: string, auth: string}, contentEncoding?: string|null}
     */
    protected function validatedPushSubscription(Request $request): array
    {
        return $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'contentEncoding' => ['nullable', 'string'],
        ]);
    }

    /**
     * @param  array{endpoint: string, keys: array{p256dh: string, auth: string}, contentEncoding?: string|null}  $validated
     */
    protected function storePushSubscription(User $user, array $validated): JsonResponse
    {
        $user->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
            $validated['contentEncoding'] ?? 'aes128gcm',
        );

        return response()->json(['status' => 'subscribed']);
    }

    protected function deletePushSubscription(User $user, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
        ]);

        $user->deletePushSubscription($validated['endpoint']);

        return response()->json(['status' => 'unsubscribed']);
    }
}
