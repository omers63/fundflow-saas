<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Support\MemberUserEmail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use RuntimeException;

class HouseholdMemberService
{
    public function __construct(
        private readonly AccountingService $accounting,
        private readonly MemberUserEmail $memberUserEmail,
    ) {}

    public function createFromApplication(MembershipApplication $application, ?Member $parentMember = null): Member
    {
        $contactEmail = strtolower(trim((string) $application->email));
        $householdEmail = $this->resolveHouseholdEmailFromApplication($application, $parentMember);

        return $this->createMemberWithUser(
            name: $application->name,
            contactEmail: $contactEmail,
            householdEmail: $householdEmail,
            password: (string) $application->password,
            phone: $application->mobile_phone ?? $application->phone,
            joinedAt: $application->membership_date ?? now(),
            parentMember: $parentMember,
        );
    }

    /**
     * @param  array{
     *     name: string,
     *     email?: string|null,
     *     phone?: string|null,
     *     monthly_contribution_amount?: float|int|string,
     *     joined_at?: Carbon|\DateTimeInterface|string,
     *     status?: string,
     *     member_number?: string,
     *     portal_pin?: string|null,
     *     parent_member_id?: int|null,
     * }  $attributes
     */
    public function createFromAdmin(array $attributes, string $password): Member
    {
        $parentMember = null;
        $parentId = $attributes['parent_member_id'] ?? null;

        if ($parentId !== null) {
            $parentMember = Member::query()->find($parentId);

            if ($parentMember === null) {
                throw new InvalidArgumentException(__('Selected parent member could not be found.'));
            }
        }

        $contactEmail = strtolower(trim((string) ($attributes['email'] ?? '')));
        $householdEmail = $parentMember !== null
            ? $this->resolveParentHouseholdEmail($parentMember)
            : ($contactEmail !== '' ? $contactEmail : throw new InvalidArgumentException(__('Email is required for a household parent.')));

        $member = $this->createMemberWithUser(
            name: (string) $attributes['name'],
            contactEmail: $contactEmail !== '' ? $contactEmail : $householdEmail,
            householdEmail: $householdEmail,
            password: $password,
            phone: $attributes['phone'] ?? null,
            joinedAt: $attributes['joined_at'] ?? now(),
            parentMember: $parentMember,
            memberNumber: $attributes['member_number'] ?? null,
            portalPin: $attributes['portal_pin'] ?? null,
            status: $attributes['status'] ?? 'active',
            monthlyContribution: $attributes['monthly_contribution_amount'] ?? 500,
        );

        if ($parentMember !== null) {
            $this->assignToHousehold($member, $parentMember, $contactEmail !== '' ? $contactEmail : null);
        }

        return $member->fresh();
    }

    public function assignToHousehold(Member $member, Member $parent, ?string $contactEmail = null): Member
    {
        $this->validateParentAssignment($member, (int) $parent->id);

        $parentHouseholdEmail = $this->resolveParentHouseholdEmail($parent);
        $contact = strtolower(trim($contactEmail ?? (string) ($member->email ?? '')));

        if ($contact === '') {
            $contact = $parentHouseholdEmail;
        }

        $flags = $this->householdAccessFlags($parentHouseholdEmail, $contact);
        $user = $member->user;

        if ($user === null) {
            throw new RuntimeException(__('Member must have a login user before joining a household.'));
        }

        if ($flags['is_separated'] && $this->memberUserEmail->isInternalLoginEmail((string) $user->email)) {
            $user->update([
                'email' => $this->memberUserEmail->resolveForUserEmailChange($contact, $user->id),
            ]);
        }

        $member->update([
            'parent_member_id' => $parent->id,
            'email' => $contact,
            'household_email' => $parentHouseholdEmail,
            'is_separated' => $flags['is_separated'],
            'direct_login_enabled' => $flags['direct_login_enabled'],
        ]);

        return $member->fresh();
    }

    public function removeFromHousehold(Member $member): Member
    {
        $contactEmail = strtolower(trim((string) ($member->email ?? $member->user?->email ?? '')));

        if ($contactEmail === '') {
            throw new InvalidArgumentException(__('Member must have an email before becoming a household parent.'));
        }

        $user = $member->user;

        if ($user === null) {
            throw new RuntimeException(__('Member must have a login user.'));
        }

        if ($this->memberUserEmail->isInternalLoginEmail((string) $user->email)) {
            $user->update([
                'email' => $this->memberUserEmail->resolveForUserEmailChange($contactEmail, $user->id),
            ]);
        }

        $member->update([
            'parent_member_id' => null,
            'household_email' => $contactEmail,
            'email' => $contactEmail,
            'is_separated' => false,
            'direct_login_enabled' => false,
        ]);

        return $member->fresh();
    }

    public function syncHouseholdAccessFlags(Member $member): Member
    {
        if ($member->parent_member_id === null) {
            $householdEmail = strtolower(trim((string) ($member->household_email ?? $member->email ?? '')));

            if ($householdEmail !== '') {
                $member->update([
                    'household_email' => $householdEmail,
                    'is_separated' => false,
                    'direct_login_enabled' => false,
                ]);
            }

            return $member->fresh();
        }

        $parent = $member->parent;

        if ($parent === null) {
            return $member;
        }

        $flags = $this->householdAccessFlags(
            $this->resolveParentHouseholdEmail($parent),
            strtolower(trim((string) ($member->email ?? $member->user?->email ?? ''))),
        );

        $member->update([
            'household_email' => $flags['household_email'],
            'is_separated' => $flags['is_separated'],
            'direct_login_enabled' => $flags['direct_login_enabled'],
        ]);

        return $member->fresh();
    }

    public function validateParentAssignment(Member $member, int $parentMemberId): void
    {
        if ($parentMemberId === (int) $member->id) {
            throw new InvalidArgumentException(__('A member cannot be their own parent.'));
        }

        $parent = Member::query()->find($parentMemberId);

        if ($parent === null) {
            throw new InvalidArgumentException(__('Selected parent member could not be found.'));
        }

        if ($parent->parent_member_id !== null) {
            throw new InvalidArgumentException(__('The selected member is a dependent. Choose the household parent instead.'));
        }

        if (in_array($parent->status, Member::PORTAL_BLOCKED_STATUSES, true)) {
            throw new InvalidArgumentException(__('The selected parent member cannot accept dependents while their membership is not active.'));
        }

        $ancestorId = $parent->id;
        $visited = [$member->id => true];

        while ($ancestorId !== null) {
            if (isset($visited[$ancestorId])) {
                throw new InvalidArgumentException(__('Invalid parent assignment: circular household relationship.'));
            }

            $visited[$ancestorId] = true;
            $ancestor = Member::query()->find($ancestorId);

            if ($ancestor === null) {
                break;
            }

            if ((int) $ancestor->id === (int) $member->id) {
                throw new InvalidArgumentException(__('Invalid parent assignment: circular household relationship.'));
            }

            $ancestorId = $ancestor->parent_member_id;
        }

        $member->load('dependents');
        $this->assertMemberNotAncestorOf($member, $parentMemberId);
    }

    public function householdHeadAllowsProfilePicker(Member $householdHead): bool
    {
        return $householdHead->parent_member_id === null;
    }

    public function memberCanUsePortal(Member $member): bool
    {
        return ! in_array($member->status, Member::PORTAL_BLOCKED_STATUSES, true);
    }

    private function createMemberWithUser(
        string $name,
        string $contactEmail,
        string $householdEmail,
        string $password,
        ?string $phone,
        mixed $joinedAt,
        ?Member $parentMember,
        ?string $memberNumber = null,
        ?string $portalPin = null,
        string $status = 'active',
        float|int|string $monthlyContribution = 500,
    ): Member {
        $parentHouseholdEmail = $parentMember !== null
            ? $this->resolveParentHouseholdEmail($parentMember)
            : $householdEmail;

        $flags = $parentMember !== null
            ? $this->householdAccessFlags($parentHouseholdEmail, $contactEmail)
            : [
                'household_email' => $householdEmail,
                'is_separated' => false,
                'direct_login_enabled' => false,
            ];

        $userLoginEmail = $flags['direct_login_enabled']
            ? $this->memberUserEmail->resolveForNewMember($contactEmail)
            : $this->memberUserEmail->resolveForNewMember($contactEmail);

        $user = User::create([
            'name' => $name,
            'email' => $userLoginEmail,
            'password' => $password,
            'is_admin' => false,
        ]);

        $member = Member::create([
            'user_id' => $user->id,
            'parent_member_id' => $parentMember?->id,
            'member_number' => $memberNumber ?? Member::generateMemberNumber(),
            'name' => $name,
            'email' => $contactEmail !== '' ? $contactEmail : $parentHouseholdEmail,
            'household_email' => $flags['household_email'],
            'phone' => $phone,
            'monthly_contribution_amount' => $monthlyContribution,
            'joined_at' => $joinedAt,
            'status' => $status,
            'portal_pin' => filled($portalPin) ? Hash::make($portalPin) : null,
            'is_separated' => $flags['is_separated'],
            'direct_login_enabled' => $flags['direct_login_enabled'],
        ]);

        $this->accounting->createMemberAccounts($member);

        return $member;
    }

    /**
     * @return array{household_email: string, is_separated: bool, direct_login_enabled: bool}
     */
    private function householdAccessFlags(string $parentHouseholdEmail, string $contactEmail): array
    {
        $parentHouseholdEmail = strtolower(trim($parentHouseholdEmail));
        $contactEmail = strtolower(trim($contactEmail));

        $isSeparated = $contactEmail !== '' && $contactEmail !== $parentHouseholdEmail;

        return [
            'household_email' => $parentHouseholdEmail,
            'is_separated' => $isSeparated,
            'direct_login_enabled' => $isSeparated,
        ];
    }

    private function resolveHouseholdEmailFromApplication(MembershipApplication $application, ?Member $parentMember): string
    {
        if ($parentMember !== null) {
            return $this->resolveParentHouseholdEmail($parentMember);
        }

        $householdEmail = strtolower(trim((string) ($application->household_email ?? '')));

        if ($householdEmail !== '') {
            return $householdEmail;
        }

        return strtolower(trim((string) $application->email));
    }

    private function resolveParentHouseholdEmail(Member $parent): string
    {
        $householdEmail = strtolower(trim((string) ($parent->household_email ?? '')));

        if ($householdEmail !== '') {
            return $householdEmail;
        }

        $email = strtolower(trim((string) ($parent->email ?? '')));

        if ($email === '') {
            throw new RuntimeException(__('Parent member must have a household email.'));
        }

        return $email;
    }

    private function assertMemberNotAncestorOf(Member $member, int $targetParentId): void
    {
        foreach ($member->dependents as $dependent) {
            if ((int) $dependent->id === $targetParentId) {
                throw new InvalidArgumentException(__('A member cannot be assigned under their own dependent.'));
            }

            $dependent->load('dependents');
            $this->assertMemberNotAncestorOf($dependent, $targetParentId);
        }
    }
}
