<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\Hash;

final class MemberHouseholdLoginService
{
    public function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public function userPasswordHash(User $user): ?string
    {
        $hash = User::query()
            ->whereKey($user->id)
            ->value('password');

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    public function verifyPassword(User $user, string $plainPassword): bool
    {
        $hash = $this->userPasswordHash($user);

        return $hash !== null && Hash::check($plainPassword, $hash);
    }

    public function memberAllowsDirectLogin(Member $member): bool
    {
        return false;
    }

    public function resolveDirectLoginUser(string $email, string $password): ?User
    {
        return null;
    }

    public function resolveHouseholdParent(string $email): ?Member
    {
        $email = $this->normalizeEmail($email);

        return Member::query()
            ->with('user')
            ->whereNull('parent_member_id')
            ->where(function ($query) use ($email): void {
                $query->where('household_email', $email)
                    ->orWhere(function ($nested) use ($email): void {
                        $nested->whereNull('household_email')->where('email', $email);
                    });
            })
            ->first();
    }

    public function resolveMemberUserByCredentials(string $email, string $password): ?User
    {
        $email = $this->normalizeEmail($email);

        $user = User::query()
            ->where('email', $email)
            ->whereHas('member')
            ->first();

        if ($user === null || ! $this->verifyPassword($user, $password)) {
            return null;
        }

        return $user;
    }
}
