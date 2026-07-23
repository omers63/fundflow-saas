<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Support\BusinessDay;
use App\Support\MemberMembershipPolicy;
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
        $householdEmail = $this->resolveHouseholdEmailFromApplication($application, $parentMember);
        $contactEmail = $parentMember !== null
            ? $householdEmail
            : strtolower(trim((string) $application->email));

        return $this->createMemberWithUser(
            name: $application->name,
            contactEmail: $contactEmail,
            householdEmail: $householdEmail,
            password: (string) $application->password,
            phone: $application->mobile_phone ?? $application->phone,
            joinedAt: $application->membership_date ?? BusinessDay::today(),
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
    public function createFromAdmin(array $attributes, string $password, bool $sendOnboardingGreeting = true): Member
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

        if ($parentMember !== null) {
            $this->assertDependentUsesHouseholdEmail($householdEmail, $contactEmail);
            $contactEmail = $householdEmail;
        }

        $member = $this->createMemberWithUser(
            name: (string) $attributes['name'],
            contactEmail: $contactEmail,
            householdEmail: $householdEmail,
            password: $password,
            phone: $attributes['phone'] ?? null,
            joinedAt: $attributes['joined_at'] ?? BusinessDay::today(),
            parentMember: $parentMember,
            memberNumber: $attributes['member_number'] ?? null,
            portalPin: $attributes['portal_pin'] ?? null,
            status: $attributes['status'] ?? 'active',
            monthlyContribution: $attributes['monthly_contribution_amount'] ?? 500,
        );

        if ($parentMember !== null) {
            $this->assignToHousehold($member, $parentMember, $householdEmail);
        }

        $member = $member->fresh() ?? $member;

        if ($sendOnboardingGreeting) {
            app(MemberOnboardingGreetingService::class)->sendToMember($member);
        }

        return $member;
    }

    public function assignToHousehold(Member $member, Member $parent, ?string $contactEmail = null): Member
    {
        $this->validateParentAssignment($member, (int) $parent->id);

        $parentHouseholdEmail = $this->resolveParentHouseholdEmail($parent);

        if ($contactEmail !== null) {
            $requestedContact = strtolower(trim($contactEmail));

            if ($requestedContact !== '' && $requestedContact !== $parentHouseholdEmail) {
                throw new InvalidArgumentException(__('Dependents must use the household parent\'s email. Unlink the parent first if this member needs their own email.'));
            }
        }

        $user = $member->user;

        if ($user === null) {
            throw new RuntimeException(__('Member must have a login user before joining a household.'));
        }

        if ($this->memberUserEmail->isInternalLoginEmail((string) $user->email) || strtolower((string) $user->email) !== $parentHouseholdEmail) {
            $user->update([
                'email' => $this->memberUserEmail->resolveForUserEmailChange($parentHouseholdEmail, $user->id),
            ]);
        }

        $member->update([
            'parent_member_id' => $parent->id,
            'email' => $parentHouseholdEmail,
            'household_email' => $parentHouseholdEmail,
            'is_separated' => false,
            'direct_login_enabled' => false,
        ]);

        return $member->fresh();
    }

    public function removeFromHousehold(Member $member): Member
    {
        $contactEmail = strtolower(trim((string) ($member->email ?? $member->user?->email ?? '')));

        if ($contactEmail === '' || $this->memberUserEmail->isInternalLoginEmail($contactEmail)) {
            $contactEmail = strtolower(trim((string) ($member->user?->email ?? '')));
        }

        if ($contactEmail === '' || $this->memberUserEmail->isInternalLoginEmail($contactEmail)) {
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

    public function establishAsHouseholdParent(Member $member, string $newEmail): Member
    {
        if ($member->parent_member_id === null) {
            throw new InvalidArgumentException(__('You are already a household parent.'));
        }

        $newEmail = strtolower(trim($newEmail));

        if (! $this->memberUserEmail->isDeliverableEmail($newEmail)) {
            throw new InvalidArgumentException(__('Enter a valid email address.'));
        }

        if ($this->memberUserEmail->isTaken($newEmail, $member->user_id)) {
            throw new InvalidArgumentException(__('This email is already in use. Choose another.'));
        }

        $user = $member->user;

        if ($user === null) {
            throw new RuntimeException(__('Member must have a login user.'));
        }

        $resolvedEmail = $this->memberUserEmail->resolveForUserEmailChange($newEmail, $user->id);

        if ($resolvedEmail !== $newEmail) {
            throw new InvalidArgumentException(__('This email is already in use. Choose another.'));
        }

        $user->update(['email' => $newEmail]);

        $member->update([
            'parent_member_id' => null,
            'email' => $newEmail,
            'household_email' => $newEmail,
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

        $parentHouseholdEmail = $this->resolveParentHouseholdEmail($parent);
        $contactEmail = strtolower(trim((string) ($member->email ?? $member->user?->email ?? '')));

        if ($contactEmail !== $parentHouseholdEmail) {
            return $this->removeFromHousehold($member);
        }

        $member->update([
            'household_email' => $parentHouseholdEmail,
            'is_separated' => false,
            'direct_login_enabled' => false,
        ]);

        return $member->fresh();
    }

    /**
     * Detach dependents that no longer qualify for household sponsorship.
     *
     * @return list<Member>
     */
    public function detachInvalidDependents(): array
    {
        $detached = [];

        Member::query()
            ->whereNotNull('parent_member_id')
            ->with('parent')
            ->orderBy('id')
            ->each(function (Member $member) use (&$detached): void {
                $parent = $member->parent;

                if ($parent === null) {
                    return;
                }

                $parentHouseholdEmail = strtolower(trim((string) ($parent->household_email ?? $parent->email ?? '')));
                $contactEmail = strtolower(trim((string) ($member->email ?? '')));

                if ($member->is_separated || ($parentHouseholdEmail !== '' && $contactEmail !== $parentHouseholdEmail)) {
                    $detached[] = $this->removeFromHousehold($member);
                }
            });

        return $detached;
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

        if ($parent->status !== 'active') {
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
        $policy = app(MemberMembershipPolicy::class);

        if (! $policy->canAccessPortal($member)) {
            return false;
        }

        if ($member->parent_member_id !== null) {
            $parent = Member::query()->find($member->parent_member_id);

            if ($parent instanceof Member && $policy->blocksHouseholdDependents($parent->status)) {
                return false;
            }
        }

        return true;
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

        if ($parentMember !== null) {
            $this->assertDependentUsesHouseholdEmail($parentHouseholdEmail, $contactEmail);
            $contactEmail = $parentHouseholdEmail;
        }

        $userLoginEmail = $this->memberUserEmail->resolveForNewMember($contactEmail);

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
            'email' => $contactEmail,
            'household_email' => $parentMember !== null ? $parentHouseholdEmail : $householdEmail,
            'phone' => $phone,
            'monthly_contribution_amount' => $monthlyContribution,
            'joined_at' => $joinedAt,
            'status' => $status,
            'portal_pin' => filled($portalPin) ? Hash::make($portalPin) : null,
            'is_separated' => false,
            'direct_login_enabled' => false,
        ]);

        $this->accounting->createMemberAccounts($member);

        return $member;
    }

    private function assertDependentUsesHouseholdEmail(string $parentHouseholdEmail, string $contactEmail): void
    {
        $parentHouseholdEmail = strtolower(trim($parentHouseholdEmail));
        $contactEmail = strtolower(trim($contactEmail));

        if ($contactEmail !== '' && $contactEmail !== $parentHouseholdEmail) {
            throw new InvalidArgumentException(__('Dependents must use the household parent\'s email. Unlink the parent first if this member needs their own email.'));
        }
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
