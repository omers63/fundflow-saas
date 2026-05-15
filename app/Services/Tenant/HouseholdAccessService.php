<?php

namespace App\Services\Tenant;

use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use InvalidArgumentException;

class HouseholdAccessService
{
    /**
     * @return array{changed: bool, rejoined: bool}
     */
    public function updateMemberLoginEmail(Member $member, User $user, string $newEmail): array
    {
        $oldEmail = (string) $user->email;
        if ($newEmail === $oldEmail) {
            return ['changed' => false, 'rejoined' => false];
        }

        $parentHouseholdEmail = (string) ($member->parent?->household_email ?? $member->parent?->email ?? '');
        $emailInUseByAnother = User::query()
            ->where('email', $newEmail)
            ->whereKeyNot($user->id)
            ->exists();

        if ($emailInUseByAnother && ! ($member->parent_member_id !== null && $newEmail === $parentHouseholdEmail)) {
            throw new InvalidArgumentException('Email already in use.');
        }

        $user->update(['email' => $newEmail]);
        $member->update(['email' => $newEmail]);

        if ($member->parent_member_id === null) {
            $member->update([
                'household_email' => $newEmail,
                'is_separated' => false,
                'direct_login_enabled' => false,
            ]);

            $member->dependents()
                ->where('is_separated', false)
                ->update([
                    'household_email' => $newEmail,
                    'direct_login_enabled' => false,
                ]);

            return ['changed' => true, 'rejoined' => false];
        }

        $isRejoin = $parentHouseholdEmail !== '' && $newEmail === $parentHouseholdEmail;
        $member->update([
            'household_email' => $parentHouseholdEmail !== '' ? $parentHouseholdEmail : $newEmail,
            'is_separated' => ! $isRejoin,
            'direct_login_enabled' => ! $isRejoin,
        ]);

        return ['changed' => true, 'rejoined' => $isRejoin];
    }
}
