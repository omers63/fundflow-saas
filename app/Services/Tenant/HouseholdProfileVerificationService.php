<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\Hash;

final class HouseholdProfileVerificationService
{
    public function memberCanUsePortal(Member $member): bool
    {
        return app(HouseholdMemberService::class)->memberCanUsePortal($member);
    }

    public function verifyMemberSecret(Member $member, string $secret, ?Member $householdParent = null): bool
    {
        $secret = trim($secret);

        if ($secret === '') {
            return false;
        }

        $user = $this->resolveLoginUser($member);

        if ($user === null) {
            return false;
        }

        $passwordHash = User::query()
            ->whereKey($user->id)
            ->value('password');

        if (! is_string($passwordHash) || $passwordHash === '') {
            return false;
        }

        if ($householdParent !== null && (int) $member->id === (int) $householdParent->id) {
            $pinHash = (string) ($member->portal_pin ?? '');

            if ($pinHash !== '') {
                return Hash::check($secret, $pinHash);
            }
        }

        return Hash::check($secret, $passwordHash);
    }

    public function resolveLoginUser(Member $member): ?User
    {
        return $member->user()->first();
    }
}
