<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Support\MemberUserEmail;
use InvalidArgumentException;

class HouseholdAccessService
{
    public function __construct(
        private readonly HouseholdMemberService $householdMembers,
        private readonly MemberUserEmail $memberUserEmail,
    ) {}

    /**
     * @return array{changed: bool, rejoined: bool, detached: bool}
     */
    public function updateMemberLoginEmail(Member $member, User $user, string $newEmail): array
    {
        $newEmail = strtolower(trim($newEmail));
        $oldEmail = strtolower(trim((string) $user->email));

        if ($newEmail === $oldEmail) {
            return ['changed' => false, 'rejoined' => false, 'detached' => false];
        }

        if ($this->memberUserEmail->isTaken($newEmail, $user->id)) {
            throw new InvalidArgumentException(__('Email already in use.'));
        }

        if ($member->parent_member_id === null) {
            $user->update(['email' => $newEmail]);

            $member->update([
                'email' => $newEmail,
                'household_email' => $newEmail,
                'is_separated' => false,
                'direct_login_enabled' => false,
            ]);

            $member->dependents()->update([
                'email' => $newEmail,
                'household_email' => $newEmail,
            ]);

            return ['changed' => true, 'rejoined' => false, 'detached' => false];
        }

        $parent = $member->parent;

        if ($parent === null) {
            $user->update(['email' => $newEmail]);
            $member->update(['email' => $newEmail]);

            return ['changed' => true, 'rejoined' => false, 'detached' => false];
        }

        $parentHouseholdEmail = strtolower(trim((string) ($parent->household_email ?? $parent->email ?? '')));

        if ($parentHouseholdEmail !== '' && $newEmail !== $parentHouseholdEmail) {
            $user->update(['email' => $newEmail]);
            $member->update(['email' => $newEmail]);
            $this->householdMembers->removeFromHousehold($member->fresh());

            return ['changed' => true, 'rejoined' => false, 'detached' => true];
        }

        $user->update([
            'email' => $this->memberUserEmail->resolveForUserEmailChange($parentHouseholdEmail, $user->id),
        ]);

        $member->update([
            'email' => $parentHouseholdEmail,
            'household_email' => $parentHouseholdEmail,
            'is_separated' => false,
            'direct_login_enabled' => false,
        ]);

        return ['changed' => true, 'rejoined' => true, 'detached' => false];
    }
}
