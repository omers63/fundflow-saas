<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Models\Tenant\ApiAccessToken;
use App\Models\Tenant\User;

final class ApiTokenAuthenticator
{
    public function authenticate(?string $bearer): ?User
    {
        if ($bearer === null || $bearer === '') {
            return null;
        }

        $hashed = hash('sha256', $bearer);

        /** @var ApiAccessToken|null $token */
        $token = ApiAccessToken::query()
            ->with('user')
            ->where('token', $hashed)
            ->first();

        if ($token === null || $token->isExpired() || $token->user === null) {
            return null;
        }

        if (! $token->user->is_admin) {
            return null;
        }

        $token->markUsed();

        $token->user->setRelation('currentApiAccessToken', $token);

        return $token->user;
    }
}
