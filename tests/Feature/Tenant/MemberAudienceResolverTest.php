<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\Tenant\MemberAudienceResolver;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    User::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);

    $this->accounting = app(AccountingService::class);
    $this->audiences = app(MemberAudienceResolver::class);
});

function makePortalMember(string $name, string $email, string $status = 'active'): Member
{
    $user = User::create([
        'name' => $name,
        'email' => $email,
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $member = Member::create([
        'user_id' => $user->id,
        'member_number' => 'MEM-'.substr(md5($email), 0, 6),
        'name' => $name,
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => $status,
    ]);

    test()->accounting->createMemberAccounts($member);

    return $member->fresh();
}

test('audience options cover operational member classes', function () {
    $keys = array_keys(MemberAudienceResolver::options());

    expect($keys)->toContain(MemberAudienceResolver::ALL_ACTIVE)
        ->and($keys)->toContain(MemberAudienceResolver::WITH_ACTIVE_LOANS)
        ->and($keys)->toContain(MemberAudienceResolver::PENDING_CONTRIBUTIONS)
        ->and($keys)->toContain(MemberAudienceResolver::DELINQUENT)
        ->and($keys)->toContain(MemberAudienceResolver::OVERDUE_CONTRIBUTIONS)
        ->and($keys)->toContain(MemberAudienceResolver::OVERDUE_LOAN_INSTALLMENTS)
        ->and($keys)->toContain(MemberAudienceResolver::GUARANTORS);
});

test('active audience excludes inactive members', function () {
    $active = makePortalMember('Active One', 'active-one@fund.test', 'active');
    makePortalMember('Inactive One', 'inactive-one@fund.test', 'inactive');

    $ids = $this->audiences->resolve(MemberAudienceResolver::ALL_ACTIVE)->pluck('id')->all();

    expect($ids)->toContain($active->id)
        ->and(count($ids))->toBe(1);
});

test('pending contributions audience uses open cycle', function () {
    $pendingMember = makePortalMember('Pending One', 'pending-one@fund.test');
    $clearMember = makePortalMember('Clear One', 'clear-one@fund.test');

    [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();

    Contribution::query()->create([
        'member_id' => $pendingMember->id,
        'period' => Contribution::periodDate($month, $year),
        'amount' => 500,
        'status' => 'pending',
    ]);

    Contribution::query()->create([
        'member_id' => $clearMember->id,
        'period' => Contribution::periodDate($month, $year),
        'amount' => 500,
        'status' => 'posted',
    ]);

    $ids = $this->audiences->resolve(MemberAudienceResolver::PENDING_CONTRIBUTIONS)->pluck('id')->all();

    expect($ids)->toContain($pendingMember->id)
        ->and($ids)->not->toContain($clearMember->id);
});

test('active loans audience includes members with active loans', function () {
    $borrower = makePortalMember('Borrower', 'borrower@fund.test');
    makePortalMember('No Loan', 'noloan@fund.test');

    Loan::factory()->for($borrower)->create([
        'status' => 'active',
    ]);

    $ids = $this->audiences->resolve(MemberAudienceResolver::WITH_ACTIVE_LOANS)->pluck('id')->all();

    expect($ids)->toContain($borrower->id)
        ->and(count($ids))->toBe(1);
});
