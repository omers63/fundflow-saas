<?php

declare(strict_types=1);

use App\Filament\Member\Widgets\MyMemberRequestsTableWidget;
use App\Filament\Tenant\Resources\CashOutRequests\Pages\ListCashOutRequests;
use App\Filament\Tenant\Resources\FundOutRequests\Pages\ListFundOutRequests;
use App\Filament\Tenant\Resources\FundPostings\Pages\ListFundPostings;
use App\Filament\Tenant\Resources\MemberCashTransferRequests\Pages\ListMemberCashTransferRequests;
use App\Filament\Tenant\Resources\MemberRequests\Pages\ListMemberRequests;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRequest;
use App\Models\Tenant\User;
use App\Services\MemberRequestInsightsService;
use Filament\Facades\Filament;
use Filament\Tables\Filters\SelectFilter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    $this->admin = User::create([
        'name' => 'Rejected Lists Admin',
        'email' => 'rejected-lists-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->memberUser = User::create([
        'name' => 'Rejected Lists Member',
        'email' => 'rejected-lists-member@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-REJ-01',
        'name' => 'Rejected Lists Member',
        'email' => 'rejected-lists-member@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
});

function assertStatusFilterHasNoDefault(Testable $component): void
{
    $filter = collect($component->instance()->getTable()->getFilters())
        ->first(fn($candidate): bool => $candidate instanceof SelectFilter && $candidate->getName() === 'status');

    expect($filter)->toBeInstanceOf(SelectFilter::class)
        ->and($filter->getDefaultState())->toBeNull();
}

test('tenant request list status filters do not default to pending', function () {
    Filament::setCurrentPanel('tenant');

    foreach ([
        ListCashOutRequests::class,
        ListFundOutRequests::class,
        ListFundPostings::class,
        ListMemberCashTransferRequests::class,
    ] as $page) {
        assertStatusFilterHasNoDefault(
            Livewire::actingAs($this->admin, 'tenant')->test($page)->assertSuccessful()
        );
    }

    // Loan eligibility override list is feature-gated (canAccess may 302); assert config directly.
    $eligibilitySource = file_get_contents(app_path('Filament/Tenant/Resources/LoanEligibilityOverrideRequests/Tables/LoanEligibilityOverrideRequestsTable.php'));
    expect($eligibilitySource)->not->toContain("->default('pending')");
});

test('admin cash outs list shows rejected rows by default', function () {
    Filament::setCurrentPanel('tenant');

    $rejected = CashOutRequest::query()->create([
        'member_id' => $this->member->id,
        'amount' => 250,
        'status' => 'rejected',
        'notes' => 'Rejected cash-out fixture',
        'reviewed_at' => now(),
        'reviewed_by' => $this->admin->id,
    ]);

    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListCashOutRequests::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$rejected]);
});

test('member portal shows rejected freeze, withdraw, and extend-freeze requests', function () {
    Filament::setCurrentPanel('member');

    $rejected = collect([
        MemberRequest::TYPE_FREEZE_MEMBERSHIP,
        MemberRequest::TYPE_WITHDRAW_MEMBERSHIP,
        MemberRequest::TYPE_EXTEND_FREEZE_MEMBERSHIP,
    ])->map(fn(string $type): MemberRequest => MemberRequest::query()->create([
            'requester_member_id' => $this->member->id,
            'type' => $type,
            'status' => MemberRequest::STATUS_REJECTED,
            'payload' => ['cycles' => 1, 'reason' => 'Fixture'],
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $this->admin->id,
            'admin_note' => 'Not approved',
        ]));

    $this->actingAs($this->memberUser, 'tenant');

    Livewire::test(MyMemberRequestsTableWidget::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords($rejected);
});

test('admin member requests rejected tab and insights link expose rejected rows', function () {
    Filament::setCurrentPanel('tenant');

    $rejected = MemberRequest::query()->create([
        'requester_member_id' => $this->member->id,
        'type' => MemberRequest::TYPE_FREEZE_MEMBERSHIP,
        'status' => MemberRequest::STATUS_REJECTED,
        'payload' => ['cycles' => 2],
        'reviewed_at' => now(),
        'reviewed_by_user_id' => $this->admin->id,
    ]);

    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListMemberRequests::class)
        ->assertSuccessful()
        ->set('activeTab', 'rejected')
        ->assertCanSeeTableRecords([$rejected]);

    $snapshot = app(MemberRequestInsightsService::class)->snapshot();

    expect($snapshot['rejected'])->toBeGreaterThan(0)
        ->and($snapshot['pipeline'])->toHaveKey('rejected_url')
        ->and($snapshot['pipeline'])->not->toHaveKey('members_url')
        ->and($snapshot['pipeline']['rejected_url'])->toContain('rejected');
});
