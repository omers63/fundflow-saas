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
     * @return array{changed: bool, rejoined: bool}
     */
    public function updateMemberLoginEmail(Member $member, User $user, string $newEmail): array
    {
        $newEmail = strtolower(trim($newEmail));
        $oldEmail = strtolower(trim((string) $user->email));

        if ($newEmail === $oldEmail) {
            return ['changed' => false, 'rejoined' => false];
        }

        if ($this->memberUserEmail->isTaken($newEmail, $user->id)) {
            throw new InvalidArgumentException(__('Email already in use.'));
        }

        $user->update(['email' => $newEmail]);

        if ($member->parent_member_id === null) {
            $member->update([
                'email' => $newEmail,
                'household_email' => $newEmail,
                'is_separated' => false,
                'direct_login_enabled' => false,
            ]);

            $member->dependents()
                ->where('is_separated', false)
                ->update([
                    'household_email' => $newEmail,
                ]);

            return ['changed' => true, 'rejoined' => false];
        }

        $parent = $member->parent;

        if ($parent === null) {
            $member->update(['email' => $newEmail]);

            return ['changed' => true, 'rejoined' => false];
        }

        $parentHouseholdEmail = strtolower(trim((string) ($parent->household_email ?? $parent->email ?? '')));
        $isRejoin = $parentHouseholdEmail !== '' && $newEmail === $parentHouseholdEmail;

        $member->update(['email' => $isRejoin ? $parentHouseholdEmail : $newEmail]);
        $member = $member->fresh();

        if ($isRejoin && $this->memberUserEmail->isInternalLoginEmail($newEmail)) {
            $user->update([
                'email' => $this->memberUserEmail->resolveForUserEmailChange($parentHouseholdEmail, $user->id),
            ]);
        }

        $this->householdMembers->syncHouseholdAccessFlags($member->fresh());

        return ['changed' => true, 'rejoined' => $isRejoin];
    }
}
