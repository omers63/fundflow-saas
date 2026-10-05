<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\Tenant\User;
use App\Support\PublicPageSettings;
use App\Support\Security\TotpService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class TwoFactorService
{
    public function __construct(
        private readonly TotpService $totp,
    ) {}

    /**
     * @return array{secret: string, uri: string, recovery_codes: list<string>}
     */
    public function beginEnrollment(User $user): array
    {
        $secret = $this->totp->generateSecret();
        $recovery = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => array_map(
                static fn (string $code): string => Hash::make($code),
                $recovery,
            ),
            'two_factor_confirmed_at' => null,
        ])->save();

        $issuer = PublicPageSettings::fundName(tenant('name')) ?: config('app.name', 'FundFlow');

        return [
            'secret' => $secret,
            'uri' => $this->totp->provisioningUri($secret, $user->email, $issuer),
            'recovery_codes' => $recovery,
        ];
    }

    public function confirmEnrollment(User $user, string $code): void
    {
        if (blank($user->two_factor_secret)) {
            throw new InvalidArgumentException(__('Start two-factor enrollment first.'));
        }

        if (! $this->totp->verify((string) $user->two_factor_secret, $code)) {
            throw new InvalidArgumentException(__('Invalid authenticator code.'));
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    }

    public function disable(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw new InvalidArgumentException(__('Password confirmation failed.'));
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    public function verifyLoginCode(User $user, string $code): bool
    {
        if (! $user->hasTwoFactorEnabled()) {
            return false;
        }

        $secret = (string) $user->two_factor_secret;

        if ($this->totp->verify($secret, $code)) {
            return true;
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $code = strtoupper(trim($code));
        $hashes = $user->two_factor_recovery_codes ?? [];

        if (! is_array($hashes) || $hashes === []) {
            return false;
        }

        foreach ($hashes as $index => $hash) {
            if (is_string($hash) && Hash::check($code, $hash)) {
                unset($hashes[$index]);
                $user->forceFill([
                    'two_factor_recovery_codes' => array_values($hashes),
                ])->save();

                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(Str::random(4).'-'.Str::random(4));
        }

        return $codes;
    }
}
