<?php

declare(strict_types=1);

use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\User;
use App\Services\MembershipApplicationApprovalService;
use App\Services\Tenant\HouseholdMemberService;
use App\Support\MemberUserEmail;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    MembershipApplication::query()->delete();
    Member::query()->delete();
    User::query()->where('is_admin', false)->delete();
});

test('approving dependent application with a different contact email creates a separated member', function () {
    $parentApplication = MembershipApplication::create([
        'name' => 'Parent Applicant',
        'email' => 'household@example.test',
        'password' => 'HouseholdPass1',
        'application_type' => 'new',
        'mobile_phone' => '0501000101',
        'iban' => 'SA030000000000101000000101',
        'status' => 'pending',
        'household_email' => 'household@example.test',
    ]);

    $childApplication = MembershipApplication::create([
        'name' => 'Adult Child',
        'email' => 'adult.child@example.test',
        'password' => 'ChildPass123',
        'application_type' => 'new',
        'mobile_phone' => '0501000102',
        'iban' => 'SA030000000000101000000102',
        'status' => 'pending',
        'household_email' => 'household@example.test',
        'parent_application_id' => $parentApplication->id,
    ]);

    app(MembershipApplicationApprovalService::class)->approveMany(collect([$parentApplication, $childApplication]));

    $parent = Member::query()->where('name', 'Parent Applicant')->first();
    $child = Member::query()->where('name', 'Adult Child')->first();

    expect($parent)->not->toBeNull()
        ->and($child)->not->toBeNull()
        ->and($child->parent_member_id)->toBe($parent->id)
        ->and($child->household_email)->toBe('household@example.test')
        ->and($child->email)->toBe('adult.child@example.test')
        ->and($child->is_separated)->toBeTrue()
        ->and($child->direct_login_enabled)->toBeTrue()
        ->and($child->user_id)->not->toBe($parent->user_id)
        ->and($child->user?->email)->toBe('adult.child@example.test');
});

test('admin can assign an existing member to a household parent with a unique email', function () {
    $parentUser = User::create([
        'name' => 'Household Parent',
        'email' => 'household@example.test',
        'password' => bcrypt('ParentPass123'),
        'is_admin' => false,
    ]);

    $parent = Member::create([
        'user_id' => $parentUser->id,
        'member_number' => 'MEM-P001',
        'name' => 'Household Parent',
        'email' => 'household@example.test',
        'household_email' => 'household@example.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    $member = app(HouseholdMemberService::class)->createFromAdmin([
        'name' => 'Later Joiner',
        'email' => 'later.joiner@example.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
        'member_number' => 'MEM-L001',
    ], 'LaterPass123');

    $linked = app(HouseholdMemberService::class)->assignToHousehold($member, $parent);

    expect($linked->parent_member_id)->toBe($parent->id)
        ->and($linked->household_email)->toBe('household@example.test')
        ->and($linked->is_separated)->toBeTrue()
        ->and($linked->direct_login_enabled)->toBeTrue()
        ->and($linked->user?->email)->toBe('later.joiner@example.test');
});

test('same household email dependents receive unique internal login emails', function () {
    $resolver = app(MemberUserEmail::class);

    User::create([
        'name' => 'Parent',
        'email' => 'household@example.test',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $second = $resolver->resolveForNewMember('household@example.test');

    expect($resolver->isInternalLoginEmail($second))->toBeTrue();
});
