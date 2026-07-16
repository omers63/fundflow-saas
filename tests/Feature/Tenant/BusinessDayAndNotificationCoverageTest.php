<?php

declare(strict_types=1);

use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\SupportRequest;
use App\Models\Tenant\User;
use App\Notifications\Tenant\LoanCancelledNotification;
use App\Notifications\Tenant\MembershipApplicationRejectedNotification;
use App\Notifications\Tenant\NewMembershipApplicationNotification;
use App\Notifications\Tenant\ReconciliationExceptionRaisedNotification;
use App\Services\Loans\LoanLifecycleService;
use App\Services\MembershipApplicationApprovalService;
use App\Services\ReconciliationService;
use App\Services\Tenant\MembershipApplicationNotificationService;
use App\Support\BusinessDaySettings;
use App\Support\PublicPageSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    app()->setLocale('en');
    BusinessDaySettings::saveFromForm(null);
    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'enrollment_open' => true,
    ]);
});

test('loan cancellation notifies the member', function () {
    Notification::fake();

    $user = User::create([
        'name' => 'Borrower',
        'email' => 'borrower-cancel@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);
    $member = Member::factory()->create(['user_id' => $user->id, 'status' => 'active']);
    $loan = Loan::factory()->for($member)->create(['status' => 'pending']);

    app(LoanLifecycleService::class)->cancelLoan($loan, 'Member withdrew application');

    Notification::assertSentTo($user, LoanCancelledNotification::class);
});

test('membership enrollment submission notifies admins', function () {
    Notification::fake();

    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin-enroll@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $application = MembershipApplication::factory()->create(['status' => 'pending']);

    app(MembershipApplicationNotificationService::class)
        ->notifyAdminsOfSubmission($application);

    Notification::assertSentTo($admin, NewMembershipApplicationNotification::class);
});

test('membership application rejection emails the applicant', function () {
    Notification::fake();

    $application = MembershipApplication::factory()->create([
        'status' => 'pending',
        'email' => 'reject-me@example.test',
    ]);

    app(MembershipApplicationApprovalService::class)->reject($application, 'Incomplete documents');

    Notification::assertSentOnDemand(MembershipApplicationRejectedNotification::class);
});

test('critical reconciliation exceptions notify admins immediately', function () {
    Notification::fake();

    $admin = User::create([
        'name' => 'Recon Admin',
        'email' => 'recon-admin@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    app(ReconciliationService::class)->raise(
        'MASTER_CASH_POOL_DRIFT',
        'master_cash',
        'critical',
        100.0,
        ['member_id' => 1],
    );

    Notification::assertSentTo($admin, ReconciliationExceptionRaisedNotification::class);
});

test('support request days open uses configured business day', function () {
    $user = User::create([
        'name' => 'Support User',
        'email' => 'support-user@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $request = SupportRequest::query()->create([
        'user_id' => $user->id,
        'category' => SupportRequest::CATEGORY_GENERAL_INQUIRY,
        'subject' => 'Test',
        'message' => 'Help',
        'status' => SupportRequest::STATUS_OPEN,
    ]);
    $request->forceFill(['created_at' => Carbon::parse('2026-06-01 10:00:00')])->save();

    BusinessDaySettings::saveFromForm('2026-06-11');

    expect($request->fresh()->daysOpen())->toBe(10);

    BusinessDaySettings::saveFromForm(null);
});
