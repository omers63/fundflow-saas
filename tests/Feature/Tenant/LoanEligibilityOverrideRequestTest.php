<?php

declare(strict_types=1);

use App\Filament\Member\Resources\MyLoans\Pages\ListMyLoans;
use App\Filament\Tenant\Resources\Loans\Pages\ListLoans;
use App\Models\Tenant\Account;
use App\Models\Tenant\LoanEligibilityOverride;
use App\Models\Tenant\LoanEligibilityOverrideRequest;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Notifications\Tenant\LoanEligibilityOverrideApprovedNotification;
use App\Notifications\Tenant\LoanEligibilityOverrideRejectedNotification;
use App\Notifications\Tenant\NewLoanEligibilityOverrideRequestNotification;
use App\Services\AccountingService;
use App\Services\Loans\LoanEligibilityOverrideRequestService;
use App\Services\Loans\LoanEligibilityService;
use App\Services\LoanService;
use App\Support\LoanEligibilityGate;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    app()->setLocale('en');
    Filament::setCurrentPanel('member');

    Account::query()->delete();
    LoanEligibilityOverride::query()->delete();
    LoanEligibilityOverrideRequest::query()->delete();
    Member::query()->delete();
    User::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);

    $this->accounting = app(AccountingService::class);
    $this->requests = app(LoanEligibilityOverrideRequestService::class);
});

function createIneligibleMemberWithUser(AccountingService $accounting): array
{
    $memberUser = User::create([
        'name' => 'Ineligible Member',
        'email' => 'ineligible-member@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $member = Member::create([
        'user_id' => $memberUser->id,
        'member_number' => 'MEM-INEL-'.uniqid(),
        'name' => 'Ineligible Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(6),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);
    $member->fundAccount->update(['balance' => 25000]);

    return [$memberUser, $member];
}

test('member can submit eligibility review from my loans page', function () {
    Notification::fake();

    [$memberUser, $member] = createIneligibleMemberWithUser($this->accounting);

    $admin = User::create([
        'name' => 'Portal Admin',
        'email' => 'portal-admin@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    Filament::setCurrentPanel('member');
    $this->actingAs($memberUser, 'tenant');

    Livewire::test(ListMyLoans::class)
        ->callAction('requestEligibilityOverride', [
            'member_message' => 'Please review my eligibility for a family emergency.',
        ])
        ->assertNotified(__('Request submitted'));

    $request = LoanEligibilityOverrideRequest::query()->where('member_id', $member->id)->first();

    expect($request)->not->toBeNull()
        ->and($request->status)->toBe('pending')
        ->and($this->requests->canSubmit($member))->toBeFalse()
        ->and($this->requests->pendingRequestFor($member))->not->toBeNull();

    Notification::assertSentTo($admin, NewLoanEligibilityOverrideRequestNotification::class);

    Livewire::test(ListMyLoans::class)
        ->assertActionVisible('eligibilityReviewPending')
        ->assertActionHidden('requestEligibilityOverride');
});

test('admin eligibility review queue lists pending member requests', function () {
    [$memberUser, $member] = createIneligibleMemberWithUser($this->accounting);

    $admin = User::create([
        'name' => 'Queue Admin',
        'email' => 'queue-admin@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $request = $this->requests->submit($member, 'Please review my blocked eligibility rules.');

    Filament::setCurrentPanel('tenant');
    $this->actingAs($admin, 'tenant');

    Livewire::test(ListLoans::class, ['activeTab' => 'eligibility_reviews'])
        ->assertCanSeeTableRecords([$request]);
});

test('ineligible member can submit eligibility override request and notify admins', function () {
    Notification::fake();

    [$memberUser, $member] = createIneligibleMemberWithUser($this->accounting);

    expect(app(LoanService::class)->checkEligibility($member)['eligible'])->toBeFalse()
        ->and($this->requests->canSubmit($member))->toBeTrue();

    $admin = User::create([
        'name' => 'Review Admin',
        'email' => 'review-admin@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $request = $this->requests->submit($member, 'I need a short-term loan for medical expenses.');

    expect($request->status)->toBe('pending')
        ->and($request->gateKeys())->toContain(LoanEligibilityGate::MEMBERSHIP_TENURE);

    Notification::assertSentTo(
        $admin,
        NewLoanEligibilityOverrideRequestNotification::class,
        function (NewLoanEligibilityOverrideRequestNotification $notification, array $channels) use ($admin): bool {
            $payload = $notification->toDatabase($admin);

            return in_array('database', $channels, true)
                && ($payload['format'] ?? null) === 'filament';
        },
    );

    expect($this->requests->canSubmit($member))->toBeFalse();
});

test('approving eligibility override request creates standing overrides and notifies member', function () {
    [$memberUser, $member] = createIneligibleMemberWithUser($this->accounting);

    $admin = User::create([
        'name' => 'Approve Admin',
        'email' => 'approve-admin@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $request = $this->requests->submit($member, 'Please review my eligibility.');

    $this->requests->approve($request, $admin->id, 'Approved after board review.');

    expect($request->fresh()->status)->toBe('approved')
        ->and(app(LoanEligibilityService::class)->isEligible($member->fresh()))->toBeTrue();

    expect(
        $memberUser->fresh()
            ->notifications()
            ->where('data->format', 'filament')
            ->where('type', LoanEligibilityOverrideApprovedNotification::class)
            ->count()
    )->toBe(1);
});

test('rejecting eligibility override request notifies member in the bell', function () {
    [$memberUser, $member] = createIneligibleMemberWithUser($this->accounting);

    $request = $this->requests->submit($member, 'Please review my eligibility.');

    $this->requests->reject($request, reviewedBy: null, adminRemarks: 'Tenure requirement not met yet.');

    expect($request->fresh()->status)->toBe('rejected');

    expect(
        $memberUser->fresh()
            ->notifications()
            ->where('data->format', 'filament')
            ->where('type', LoanEligibilityOverrideRejectedNotification::class)
            ->count()
    )->toBe(1);
});
