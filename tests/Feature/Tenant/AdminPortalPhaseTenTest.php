<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\LoanEmiCollectionCalendarPage;
use App\Filament\Tenant\Pages\MessagesInboxPage;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Resources\Members\Pages\EditMember;
use App\Filament\Tenant\Resources\Members\RelationManagers\GuarantorExposureRelationManager;
use App\Filament\Tenant\Resources\SupportRequests\Pages\ListSupportRequests;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberAnnouncement;
use App\Models\Tenant\SupportRequest;
use App\Models\Tenant\User;
use App\Services\Loans\LoanEmiCollectionCalendarService;
use App\Services\Members\MemberGuarantorExposureService;
use App\Services\Tenant\MemberAnnouncementService;
use App\Services\Tenant\SupportRequestWorkflowService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

test('guarantor exposure tab is visible when member guarantees a loan', function () {
    $guarantor = Member::factory()->create();
    $borrower = Member::factory()->create();

    Loan::factory()->create([
        'member_id' => $borrower->id,
        'guarantor_member_id' => $guarantor->id,
        'status' => 'active',
        'amount_approved' => 5000,
        'amount_disbursed' => 5000,
    ]);

    expect(GuarantorExposureRelationManager::canViewForRecord($guarantor, EditMember::class))->toBeTrue()
        ->and(GuarantorExposureRelationManager::canViewForRecord($borrower, EditMember::class))->toBeFalse();
});

test('member guarantor exposure service summarizes outstanding exposure', function () {
    $guarantor = Member::factory()->create();
    $borrower = Member::factory()->create();

    Loan::factory()->create([
        'member_id' => $borrower->id,
        'guarantor_member_id' => $guarantor->id,
        'status' => 'active',
        'amount_approved' => 4000,
        'amount_disbursed' => 4000,
        'total_repaid' => 1000,
        'late_repayment_count' => 99,
    ]);

    LoanInstallment::create([
        'loan_id' => Loan::query()->where('guarantor_member_id', $guarantor->id)->value('id'),
        'installment_number' => 1,
        'amount' => 3000,
        'due_date' => now()->addWeek(),
        'status' => 'pending',
    ]);

    $summary = app(MemberGuarantorExposureService::class)->summaryForMember($guarantor);

    expect($summary['loan_count'])->toBe(1)
        ->and($summary['has_risk'])->toBeTrue()
        ->and($summary['total_exposure'])->toBeGreaterThan(0);
});

test('support request workflow records reply and updates status', function () {
    $admin = User::create([
        'name' => 'Support Admin',
        'email' => 'support-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $member = Member::factory()->create([
        'user_id' => User::create([
            'name' => 'Support Member',
            'email' => 'support-member@fund.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ])->id,
    ]);

    $request = SupportRequest::query()->create([
        'user_id' => $member->user_id,
        'member_id' => $member->id,
        'category' => SupportRequest::CATEGORY_GENERAL_INQUIRY,
        'subject' => 'Need help',
        'message' => 'Please call me back.',
        'status' => SupportRequest::STATUS_OPEN,
    ]);

    $workflow = app(SupportRequestWorkflowService::class);
    $workflow->addReply($request, $admin, 'We will review this today.');

    $request->refresh();

    expect($request->status)->toBe(SupportRequest::STATUS_IN_PROGRESS)
        ->and($request->replies)->toHaveCount(1);
});

test('support requests list renders workflow columns', function () {
    Filament::setCurrentPanel('tenant');

    $admin = User::create([
        'name' => 'Support Lister',
        'email' => 'support-lister@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    SupportRequest::query()->create([
        'user_id' => $admin->id,
        'category' => SupportRequest::CATEGORY_OTHER,
        'subject' => 'Test ticket',
        'message' => 'Body',
        'status' => SupportRequest::STATUS_OPEN,
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(ListSupportRequests::class)
        ->assertSuccessful()
        ->assertSee(__('Manage'));
});

test('emi collection calendar builds month grid', function () {
    $member = Member::factory()->create();
    $loan = Loan::factory()->create([
        'member_id' => $member->id,
        'status' => 'active',
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'due_date' => now()->startOfMonth()->addDays(4),
        'status' => 'pending',
        'amount' => 250,
    ]);

    $grid = app(LoanEmiCollectionCalendarService::class)->monthGrid((int) now()->year, (int) now()->month);

    expect(collect($grid)->sum('total'))->toBeGreaterThan(0);
});

test('emi collection calendar page renders', function () {
    Filament::setCurrentPanel('tenant');

    $admin = User::create([
        'name' => 'Calendar Admin',
        'email' => 'calendar-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(LoanEmiCollectionCalendarPage::class)
        ->assertSuccessful()
        ->assertSee(__('EMI collection calendar'));
});

test('member announcement service resolves active audience', function () {
    $before = app(MemberAnnouncementService::class)->previewCount(MemberAnnouncement::AUDIENCE_ALL_ACTIVE);

    Member::factory()->create([
        'status' => 'active',
        'user_id' => User::create([
            'name' => 'Active Announce One',
            'email' => 'active-announce-one@fund.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ])->id,
    ]);

    Member::factory()->create([
        'status' => 'active',
        'user_id' => User::create([
            'name' => 'Active Announce Two',
            'email' => 'active-announce-two@fund.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ])->id,
    ]);

    Member::factory()->create(['status' => 'inactive']);

    expect(app(MemberAnnouncementService::class)->previewCount(MemberAnnouncement::AUDIENCE_ALL_ACTIVE))
        ->toBe($before + 2);
});

test('messages inbox exposes compose announcement action', function () {
    Filament::setCurrentPanel('tenant');

    $admin = User::create([
        'name' => 'Messages Admin',
        'email' => 'messages-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(MessagesInboxPage::class)
        ->assertSuccessful()
        ->mountAction('compose_announcement')
        ->assertActionMounted('compose_announcement');
});

test('scheduled member announcement dispatches when due', function () {
    $admin = User::create([
        'name' => 'Schedule Admin',
        'email' => 'schedule-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Member::factory()->create([
        'status' => 'active',
        'user_id' => User::create([
            'name' => 'Schedule Member',
            'email' => 'schedule-member@fund.test',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ])->id,
    ]);

    $announcement = MemberAnnouncement::query()->create([
        'created_by_user_id' => $admin->id,
        'audience' => MemberAnnouncement::AUDIENCE_ALL_ACTIVE,
        'title_en' => 'Scheduled title',
        'body_en' => 'Scheduled body',
        'channels' => [MemberAnnouncement::CHANNEL_IN_APP],
        'scheduled_for' => now()->subMinute(),
    ]);

    expect(app(MemberAnnouncementService::class)->dispatchDueScheduled())->toBe(1);

    $announcement->refresh();

    expect($announcement->sent_at)->not->toBeNull()
        ->and($announcement->recipient_count)->toBeGreaterThan(0);
});

test('future scheduled member announcement is not dispatched', function () {
    $admin = User::create([
        'name' => 'Future Schedule Admin',
        'email' => 'future-schedule-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $announcement = MemberAnnouncement::query()->create([
        'created_by_user_id' => $admin->id,
        'audience' => MemberAnnouncement::AUDIENCE_ALL_ACTIVE,
        'title_en' => 'Future title',
        'body_en' => 'Future body',
        'channels' => [MemberAnnouncement::CHANNEL_IN_APP],
        'scheduled_for' => now()->addHour(),
    ]);

    expect(app(MemberAnnouncementService::class)->dispatchDueScheduled())->toBe(0);

    $announcement->refresh();

    expect($announcement->sent_at)->toBeNull();
});

test('announcements dispatch scheduled command runs for tenant', function () {
    $admin = User::create([
        'name' => 'Command Schedule Admin',
        'email' => 'command-schedule-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    MemberAnnouncement::query()->create([
        'created_by_user_id' => $admin->id,
        'audience' => MemberAnnouncement::AUDIENCE_ALL_ACTIVE,
        'title_en' => 'Command title',
        'body_en' => 'Command body',
        'channels' => [MemberAnnouncement::CHANNEL_IN_APP],
        'scheduled_for' => now()->subMinute(),
    ]);

    $this->artisan('announcements:dispatch-scheduled', ['--tenants' => ['testing']])
        ->assertSuccessful();
});

test('member edit page includes guarantor relation manager in registry', function () {
    expect(MemberResource::getRelations())->toContain(GuarantorExposureRelationManager::class);
});
