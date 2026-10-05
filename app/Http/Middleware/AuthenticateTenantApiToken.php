<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Api\ApiTokenAuthenticator;
use App\Support\Billing\TenantFeatureGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateTenantApiToken
{
    public function __construct(
        private readonly ApiTokenAuthenticator $authenticator,
        private readonly TenantFeatureGate $features,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        if (! $this->features->allows(TenantFeatureGate::FEATURE_API)) {
            return response()->json(['message' => __('API access is not enabled for this plan.')], 403);
        }

        $header = $request->bearerToken();
        $user = $this->authenticator->authenticate($header);

        if ($user === null) {
            return response()->json(['message' => __('Unauthenticated.')], 401);
        }

        auth('tenant')->setUser($user);
        $request->setUserResolver(static fn () => $user);

        $token = $user->getRelation('currentApiAccessToken');

        foreach ($abilities as $ability) {
            if ($ability !== '' && ! $token->can($ability)) {
                return response()->json(['message' => __('Missing ability: :ability', ['ability' => $ability])], 403);
            }
        }

        return $next($request);
    }
}
