<?php

declare(strict_types=1);

namespace App\Models\Tenant\Concerns;

use App\Models\Tenant\ApiAccessToken;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

trait IssuesApiTokens
{
    public function apiAccessTokens(): HasMany
    {
        return $this->hasMany(ApiAccessToken::class);
    }

    /**
     * @param  list<string>  $abilities
     * @return array{token: ApiAccessToken, plain_text: string}
     */
    public function issueApiToken(string $name, array $abilities = ['*'], ?\DateTimeInterface $expiresAt = null): array
    {
        $plain = Str::random(40);
        $token = $this->apiAccessTokens()->create([
            'name' => $name,
            'token' => hash('sha256', $plain),
            'abilities' => $abilities === [] ? ['*'] : array_values($abilities),
            'expires_at' => $expiresAt,
        ]);

        return [
            'token' => $token,
            'plain_text' => $plain,
        ];
    }

    public function findApiToken(string $plainText): ?ApiAccessToken
    {
        $hashed = hash('sha256', $plainText);

        /** @var ApiAccessToken|null $token */
        $token = $this->apiAccessTokens()->where('token', $hashed)->first();

        return $token;
    }
}
