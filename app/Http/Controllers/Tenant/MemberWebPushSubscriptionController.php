<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\Concerns\InteractsWithPushSubscriptions;
use App\Models\Tenant\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MemberWebPushSubscriptionController extends Controller
{
    use InteractsWithPushSubscriptions;

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('tenant');

        abort_unless($user->activeMember() !== null, Response::HTTP_FORBIDDEN);

        return $this->storePushSubscription($user, $this->validatedPushSubscription($request));
    }

    public function destroy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('tenant');

        abort_unless($user->activeMember() !== null, Response::HTTP_FORBIDDEN);

        return $this->deletePushSubscription($user, $request);
    }
}
