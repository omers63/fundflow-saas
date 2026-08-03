<?php

declare(strict_types=1);

use App\Filament\Member\Widgets\MembershipFreezeStatusWidget;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRequest;
use App\Models\Tenant\User;
use App\Notifications\Tenant\MemberStatusChangedNotification;
use App\Services\AccountingService;
use App\Services\MemberFreezeService;
use App\Services\Tenant\MemberRequestService;
use App\Support\BusinessDaySettings;
use App\Support\MemberMembershipPolicy;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    BusinessDaySettings::saveFromForm('2026-05-15');

    $this->user = User::create([
        'name' => 'Freeze Workflow',
        'email' => 'freeze-workflow@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->user->id,
        'member_number' => 'MEM-FRZ-WF',
        'name' => 'Freeze Workflow',
        'email' => 'freeze-workflow@fund.test',
        'phone' => '0500000777',
        'monthly_contribution_amount' => 1000,
        'joined_at' => Carbon::parse('2025-01-01'),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);
});

afterEach(function () {
    BusinessDaySettings::saveFromForm(null);
});

test('zero or blank freeze cycles mean indefinite plan with ongoing protection', function () {
    $freezes = app(MemberFreezeService::class);

    expect(MemberFreezeService::normalizeCycles(null))->toBe(0)
        ->and(MemberFreezeService::normalizeCycles(''))->toBe(0)
        ->and(MemberFreezeService::normalizeCycles(0))->toBe(0)
        ->and(MemberFreezeService::normalizeCycles(3))->toBe(3);

    $freezes->applyFreeze($this->member, [
        'cycles' => 0,
        'household_mode' => MemberFreezeService::HOUSEHOLD_SELF_ONLY,
        'reason' => 'Open-ended',
    ]);

    $this->member->refresh();

    expect($freezes->isIndefiniteFreeze($this->member))->toBeTrue()
        ->and($freezes->isWithinFreezePlan($this->member))->toBeTrue()
        ->and($freezes->isFreezePlanExhausted($this->member))->toBeFalse()
        ->and((int) $this->member->freeze_cycles_requested)->toBe(0)
        ->and($this->member->freeze_plan_ended_at)->toBeNull()
        ->and((int) $this->member->freeze_emi_cycles_pushed)->toBe(1);

    $freezes->onContributionCycleOpened(6, 2026);

    $this->member->refresh();

    expect($freezes->isWithinFreezePlan($this->member))->toBeTrue()
        ->and($this->member->freeze_plan_ended_at)->toBeNull()
        ->and((int) $this->member->freeze_emi_cycles_pushed)->toBe(2);
});

test('blank cycles on freeze request payload are stored as indefinite', function () {
    $service = app(MemberRequestService::class);

    $request = $service->submit($this->member, MemberRequest::TYPE_FREEZE_MEMBERSHIP, [
        'cycles' => '',
        'household_mode' => MemberFreezeService::HOUSEHOLD_SELF_ONLY,
        'reason' => 'No end date',
    ]);

    expect((int) ($request->payload['cycles'] ?? -1))->toBe(0)
        ->and(MemberFreezeService::formatCyclesLabel(0))->toBe(__('Indefinite'));
});

test('frozen members get read-only portal and blocked cash-out', function () {
    $policy = app(MemberMembershipPolicy::class);
    $freezes = app(MemberFreezeService::class);

    $freezes->applyFreeze($this->member, [
        'cycles' => 2,
        'household_mode' => MemberFreezeService::HOUSEHOLD_SELF_ONLY,
        'reason' => 'Travel',
    ]);

    $this->member->refresh();

    expect($policy->isFrozen($this->member))->toBeTrue()
        ->and($policy->canAccessPortal($this->member))->toBeTrue()
        ->and($policy->isFrozenReadOnly($this->member))->toBeTrue()
        ->and($policy->canMutatePortal($this->member))->toBeFalse()
        ->and($policy->canRequestCashOut($this->member))->toBeFalse()
        ->and($policy->canParticipateInContributionCycles($this->member))->toBeFalse()
        ->and($freezes->isWithinFreezePlan($this->member))->toBeTrue()
        ->and((int) $this->member->freeze_cycles_requested)->toBe(2)
        ->and((int) $this->member->freeze_cycles_remaining)->toBe(1)
        ->and((int) $this->member->freeze_emi_cycles_pushed)->toBe(1);
});

test('freeze pushes unpaid emis and early unfreeze pulls them back', function () {
    $tier = LoanTier::query()->create([
        'tier_number' => 77,
        'label' => 'Freeze Tier',
        'min_amount' => 1000,
        'max_amount' => 50000,
        'min_monthly_installment' => 500,
        'is_active' => true,
    ]);

    $loan = Loan::query()->create([
        'member_id' => $this->member->id,
        'loan_tier_id' => $tier->id,
        'amount' => 10000,
        'amount_requested' => 10000,
        'amount_approved' => 10000,
        'amount_disbursed' => 10000,
        'interest_rate' => 0,
        'term_months' => 10,
        'monthly_repayment' => 1000,
        'status' => 'active',
        'applied_at' => '2026-01-01',
        'approved_at' => '2026-01-05',
        'disbursed_at' => '2026-01-10',
        'first_repayment_month' => 5,
        'first_repayment_year' => 2026,
    ]);

    $installment = LoanInstallment::query()->create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => '2026-05-10',
        'status' => 'overdue',
        'is_late' => true,
        'late_fee_amount' => 50,
    ]);

    $originalDue = $installment->due_date->toDateString();

    app(MemberFreezeService::class)->applyFreeze($this->member, [
        'cycles' => 2,
        'reason' => 'Loan defer',
    ]);

    $installment->refresh();
    expect($installment->status)->toBe('pending')
        ->and($installment->is_late)->toBeFalse()
        ->and((float) $installment->late_fee_amount)->toEqual(0.0)
        ->and($installment->due_date->toDateString())->not->toBe($originalDue);

    $pushedDue = $installment->due_date->copy();

    app(MemberFreezeService::class)->applyUnfreeze($this->member->fresh());

    $installment->refresh();
    expect($installment->due_date->lessThan($pushedDue))->toBeTrue()
        ->and($this->member->fresh()->status)->toBe('active')
        ->and($this->member->fresh()->frozen_at)->toBeNull();
});

test('freeze is blocked while member still guarantees another loan', function () {
    $borrowerUser = User::create([
        'name' => 'Borrower Freeze',
        'email' => 'borrower-freeze@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
    ]);
    $borrower = Member::create([
        'user_id' => $borrowerUser->id,
        'member_number' => 'MEM-FRZ-BOR',
        'name' => 'Borrower Freeze',
        'email' => 'borrower-freeze@fund.test',
        'phone' => '0500000778',
        'monthly_contribution_amount' => 1000,
        'joined_at' => Carbon::parse('2025-01-01'),
        'status' => 'active',
    ]);

    $tier = LoanTier::query()->create([
        'tier_number' => 78,
        'label' => 'Guarantor Freeze Tier',
        'min_amount' => 1000,
        'max_amount' => 50000,
        'min_monthly_installment' => 500,
        'is_active' => true,
    ]);

    Loan::query()->create([
        'member_id' => $borrower->id,
        'guarantor_member_id' => $this->member->id,
        'guarantor_name' => $this->member->name,
        'loan_tier_id' => $tier->id,
        'amount' => 5000,
        'amount_requested' => 5000,
        'amount_approved' => 5000,
        'amount_disbursed' => 5000,
        'interest_rate' => 0,
        'term_months' => 5,
        'monthly_repayment' => 1000,
        'status' => 'active',
        'applied_at' => '2026-01-01',
        'approved_at' => '2026-01-05',
        'disbursed_at' => '2026-01-10',
    ]);

    $reasons = app(MemberFreezeService::class)->blockingReasons($this->member, forApprove: true);

    expect($reasons)->not->toBeEmpty();

    expect(fn () => app(MemberFreezeService::class)->applyFreeze($this->member, [
        'cycles' => 1,
        'reason' => 'blocked',
    ]))->toThrow(ValidationException::class);
});

test('pre-submit notify alerts borrowers who still need a guarantor replacement', function () {
    Notification::fake();

    $borrowerUser = User::create([
        'name' => 'Borrower Notify',
        'email' => 'borrower-notify@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
    ]);
    $borrower = Member::create([
        'user_id' => $borrowerUser->id,
        'member_number' => 'MEM-FRZ-NTF',
        'name' => 'Borrower Notify',
        'email' => 'borrower-notify@fund.test',
        'phone' => '0500000779',
        'monthly_contribution_amount' => 1000,
        'joined_at' => Carbon::parse('2025-01-01'),
        'status' => 'active',
    ]);

    $tier = LoanTier::query()->create([
        'tier_number' => 79,
        'label' => 'Notify Guarantor Tier',
        'min_amount' => 1000,
        'max_amount' => 50000,
        'min_monthly_installment' => 500,
        'is_active' => true,
    ]);

    $loan = Loan::query()->create([
        'member_id' => $borrower->id,
        'guarantor_member_id' => $this->member->id,
        'guarantor_name' => $this->member->name,
        'loan_tier_id' => $tier->id,
        'amount' => 5000,
        'amount_requested' => 5000,
        'amount_approved' => 5000,
        'amount_disbursed' => 5000,
        'interest_rate' => 0,
        'term_months' => 5,
        'monthly_repayment' => 1000,
        'status' => 'active',
        'applied_at' => '2026-01-01',
        'approved_at' => '2026-01-05',
        'disbursed_at' => '2026-01-10',
    ]);

    $freezes = app(MemberFreezeService::class);

    $result = $freezes->notifyBorrowersToReplaceGuarantor($this->member);

    expect($result['notified'])->toBe(1)
        ->and($result['loan_ids'])->toContain($loan->id);

    Notification::assertSentTo(
        $borrowerUser,
        MemberStatusChangedNotification::class,
        function (MemberStatusChangedNotification $notification) use ($loan): bool {
            return $notification->title === __('Guarantor freeze pending')
                && str_contains($notification->body, (string) $loan->id)
                && filled($notification->url);
        },
    );

    expect(fn () => $freezes->notifyBorrowersToReplaceGuarantor($this->member))
        ->toThrow(ValidationException::class);
});

test('portal blocked statuses no longer include inactive so frozen members can enter', function () {
    expect(Member::PORTAL_BLOCKED_STATUSES)->toBe(['withdrawn'])
        ->and(Member::PORTAL_BLOCKED_STATUSES)->not->toContain('inactive');

    $policy = app(MemberMembershipPolicy::class);
    $frozen = Member::factory()->make([
        'status' => 'inactive',
        'frozen_at' => now(),
    ]);
    $hold = Member::factory()->make([
        'status' => 'inactive',
        'frozen_at' => null,
        'contribution_cycles_active' => true,
    ]);

    expect($policy->canAccessPortal($frozen))->toBeTrue()
        ->and($policy->isPortalAccessBlocked($hold))->toBeTrue()
        ->and($policy->canAccessPortal($hold))->toBeFalse();
});

test('guarantor replacement banner is freeze-gated and clears after freeze request rejection', function () {
    $borrowerUser = User::create([
        'name' => 'Guaranteed Borrower',
        'email' => 'guaranteed-borrower@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
    ]);

    $borrower = Member::create([
        'user_id' => $borrowerUser->id,
        'member_number' => 'MEM-FRZ-BOR',
        'name' => 'Guaranteed Borrower',
        'email' => 'guaranteed-borrower@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => Carbon::parse('2025-01-01'),
        'status' => 'active',
    ]);

    $tier = LoanTier::query()->create([
        'tier_number' => 88,
        'label' => 'Guarantor Banner Tier',
        'min_amount' => 1000,
        'max_amount' => 50000,
        'min_monthly_installment' => 500,
        'is_active' => true,
    ]);

    Loan::query()->create([
        'member_id' => $borrower->id,
        'guarantor_member_id' => $this->member->id,
        'loan_tier_id' => $tier->id,
        'amount' => 5000,
        'amount_requested' => 5000,
        'amount_approved' => 5000,
        'amount_disbursed' => 5000,
        'interest_rate' => 0,
        'term_months' => 5,
        'monthly_repayment' => 1000,
        'status' => 'active',
        'applied_at' => '2026-01-01',
        'approved_at' => '2026-01-05',
        'disbursed_at' => '2026-01-10',
    ]);

    $freezes = app(MemberFreezeService::class);
    $admin = User::create([
        'name' => 'Freeze Admin',
        'email' => 'freeze-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    // Active guarantor with no freeze in play must not see the dashboard banner.
    expect($freezes->shouldPromptGuarantorReplacement($this->member))->toBeFalse()
        ->and($freezes->unresolvedGuarantorLoans($this->member))->not->toBeEmpty();

    $this->actingAs($this->user, 'tenant');
    Filament\Facades\Filament::setCurrentPanel('member');
    expect(MembershipFreezeStatusWidget::canView())->toBeFalse();

    // Pending freeze request (created directly — submit is blocked while guaranteeing loans)
    // still surfaces the replacement prompt until rejected.
    $request = MemberRequest::query()->create([
        'requester_member_id' => $this->member->id,
        'type' => MemberRequest::TYPE_FREEZE_MEMBERSHIP,
        'status' => MemberRequest::STATUS_PENDING,
        'payload' => [
            'cycles' => 1,
            'household_mode' => MemberFreezeService::HOUSEHOLD_SELF_ONLY,
            'reason' => 'Travel',
        ],
    ]);

    expect($freezes->shouldPromptGuarantorReplacement($this->member))->toBeTrue()
        ->and($freezes->hasPendingFreezeRequest($this->member))->toBeTrue()
        ->and(MembershipFreezeStatusWidget::canView())->toBeTrue();

    app(MemberRequestService::class)->reject($request, $admin, 'Not approved');

    expect($freezes->hasPendingFreezeRequest($this->member))->toBeFalse()
        ->and($freezes->shouldPromptGuarantorReplacement($this->member))->toBeFalse()
        ->and($freezes->isFrozen($this->member))->toBeFalse()
        ->and(MembershipFreezeStatusWidget::canView())->toBeFalse();
});
