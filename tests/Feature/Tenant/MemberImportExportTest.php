<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Members\Pages\ListMembers;
use App\Filament\Tenant\Resources\Members\Pages\ViewMember;
use App\Models\Central\Tenant;
use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanEligibilityOverride;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\MemberAnnualSubscriptionFeeService;
use App\Services\MemberExportService;
use App\Services\MemberImportService;
use App\Support\BusinessDay;
use App\Support\LoanEligibilityGate;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    $tenant = Tenant::find('testing');
    $this->domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $this->domain)->exists()) {
        $tenant->domains()->create(['domain' => $this->domain]);
    }

    $this->admin = User::create([
        'name' => 'Member Import Admin',
        'email' => 'member-import@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($this->admin, 'tenant');

    Account::query()->delete();
    Member::query()->delete();
    LoanEligibilityOverride::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 500_000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 500_000, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);

    $this->accounting = app(AccountingService::class);
});

function writeMemberImportCsv(string $contents): string
{
    $path = sys_get_temp_dir().'/member-import-'.uniqid('', true).'.csv';
    file_put_contents($path, $contents);

    return $path;
}

test('member import creates member from member_number without email', function () {
    $path = writeMemberImportCsv(
        "member_number,name,monthly_contribution_amount\n".
        "LEG-NO-EMAIL,Number Only Member,500\n"
    );

    $result = app(MemberImportService::class)->import($path, 'TempPass@123');

    expect($result['created'])->toBe(1)
        ->and($result['failed'])->toBe(0);

    $member = Member::query()->where('member_number', 'LEG-NO-EMAIL')->first();

    expect($member)->not->toBeNull()
        ->and($member->name)->toBe('Number Only Member')
        ->and($member->email)->toContain('@');
});

test('member import creates member with opening balances from csv', function () {
    $path = writeMemberImportCsv(
        "name,email,monthly_contribution_amount,joined_at,contribution_arrears_cutoff_date,cutoff_cash_balance,cutoff_fund_balance\n".
        "Imported Member,imported.member@fund.test,1000,2024-03-01,2024-12-31,500,1200\n"
    );

    $result = app(MemberImportService::class)->import($path, 'TempPass@123');

    expect($result['created'])->toBe(1)
        ->and($result['failed'])->toBe(0);

    $member = Member::query()->where('email', 'imported.member@fund.test')->first();

    expect($member)->not->toBeNull()
        ->and((float) $member->monthly_contribution_amount)->toBe(1000.0)
        ->and($member->contribution_arrears_cutoff_date?->toDateString())->toBe('2024-12-31')
        ->and((float) $member->cashAccount->balance)->toBe(500.0)
        ->and((float) $member->fundAccount->balance)->toBe(1200.0);
});

test('member import skips existing email', function () {
    Member::create([
        'member_number' => 'MEM-EXISTING',
        'name' => 'Existing Member',
        'email' => 'existing.member@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    $path = writeMemberImportCsv(
        "name,email\nDuplicate Member,existing.member@fund.test\n"
    );

    $result = app(MemberImportService::class)->import($path, 'TempPass@123');

    expect($result['created'])->toBe(0)
        ->and($result['skipped'])->toBe(1)
        ->and(Member::query()->where('email', 'existing.member@fund.test')->count())->toBe(1);
});

test('member import links dependent when parent row appears later in the file', function () {
    $path = writeMemberImportCsv(
        "member_number,name,email,parent_member_number\n".
        "IMP-CHILD-1,Import Child,,IMP-PARENT-1\n".
        "IMP-PARENT-1,Import Parent,import.parent@fund.test,\n"
    );

    $result = app(MemberImportService::class)->import($path, 'TempPass@123');

    expect($result['created'])->toBe(2)
        ->and($result['failed'])->toBe(0);

    $parent = Member::query()->where('member_number', 'IMP-PARENT-1')->first();
    $child = Member::query()->where('member_number', 'IMP-CHILD-1')->first();

    expect($parent)->not->toBeNull()
        ->and($child)->not->toBeNull()
        ->and($child->parent_member_id)->toBe($parent->id);
});

test('member import links dependent when parent is referenced by email from a later row', function () {
    $path = writeMemberImportCsv(
        "member_number,name,email,parent_member_email\n".
        "IMP-CHILD-2,Import Child By Email,,import.parent.email@fund.test\n".
        "IMP-PARENT-2,Import Parent By Email,import.parent.email@fund.test,\n"
    );

    $result = app(MemberImportService::class)->import($path, 'TempPass@123');

    expect($result['created'])->toBe(2)
        ->and($result['failed'])->toBe(0);

    $parent = Member::query()->where('member_number', 'IMP-PARENT-2')->first();
    $child = Member::query()->where('member_number', 'IMP-CHILD-2')->first();

    expect($child?->parent_member_id)->toBe($parent?->id);
});

test('member import fails when parent reference never resolves', function () {
    $path = writeMemberImportCsv(
        "member_number,name,email,parent_member_number\n".
        "IMP-ORPHAN-1,Import Orphan,,IMP-MISSING-PARENT\n"
    );

    $result = app(MemberImportService::class)->import($path, 'TempPass@123');

    expect($result['created'])->toBe(0)
        ->and($result['failed'])->toBe(1)
        ->and($result['errors'][0])->toContain('IMP-MISSING-PARENT')
        ->and(Member::query()->where('member_number', 'IMP-ORPHAN-1')->exists())->toBeFalse();
});

test('member import links dependent when parent_member_number contains legacy household shorthand name', function () {
    $path = writeMemberImportCsv(
        "member_number,name,email,parent_member_number\n".
        "1,باسم سليمان إبراهيم سمان,head.samman@fund.test,\n".
        "2,خديجة عبدالحكيم أميرشاه عصمت,,باسم سليمان سمان\n"
    );

    $result = app(MemberImportService::class)->import($path, 'TempPass@123');

    expect($result['created'])->toBe(2)
        ->and($result['failed'])->toBe(0);

    $parent = Member::query()->where('member_number', '1')->first();
    $child = Member::query()->where('member_number', '2')->first();

    expect($parent)->not->toBeNull()
        ->and($child)->not->toBeNull()
        ->and($child->parent_member_id)->toBe($parent->id);
});

test('member import links dependent when parent shorthand appears before household head row', function () {
    $path = writeMemberImportCsv(
        "member_number,name,email,parent_member_number\n".
        "9,2 فايزة سليمان إبراهيم سمان,,نبيل سليمان سمان\n".
        "29,نبيل سليمان إبراهيم سمان,head.nabil@fund.test,نبيل سليمان سمان\n"
    );

    $result = app(MemberImportService::class)->import($path, 'TempPass@123');

    expect($result['created'])->toBe(2)
        ->and($result['failed'])->toBe(0);

    $parent = Member::query()->where('member_number', '29')->first();
    $child = Member::query()->where('member_number', '9')->first();

    expect($parent)->not->toBeNull()
        ->and($child)->not->toBeNull()
        ->and($child->parent_member_id)->toBe($parent->id);
});

test('member import creates dependents that share a household contact email when member_number is present', function () {
    $path = writeMemberImportCsv(
        "member_number,name,email,parent_member_number\n".
        "1,Household Head,shared.household@fund.test,\n".
        "2,Household Dependent One,shared.household@fund.test,1\n".
        "3,Household Dependent Two,shared.household@fund.test,1\n"
    );

    $result = app(MemberImportService::class)->import($path, 'TempPass@123');

    expect($result['created'])->toBe(3)
        ->and($result['skipped'])->toBe(0)
        ->and($result['failed'])->toBe(0)
        ->and(Member::query()->count())->toBe(3);

    $head = Member::query()->where('member_number', '1')->first();
    $firstDependent = Member::query()->where('member_number', '2')->first();
    $secondDependent = Member::query()->where('member_number', '3')->first();

    expect($head?->email)->toBe('shared.household@fund.test')
        ->and($firstDependent?->parent_member_id)->toBe($head?->id)
        ->and($secondDependent?->parent_member_id)->toBe($head?->id)
        ->and($firstDependent?->email)->toBe('shared.household@fund.test')
        ->and($secondDependent?->email)->toBe('shared.household@fund.test');
});

test('member import resumes missing rows when household members share contact emails', function () {
    Member::create([
        'member_number' => '1',
        'name' => 'Household Head',
        'email' => 'shared.household@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $path = writeMemberImportCsv(
        "member_number,name,email,parent_member_number\n".
        "1,Household Head,shared.household@fund.test,\n".
        "2,Household Dependent One,shared.household@fund.test,1\n".
        "3,Household Dependent Two,shared.household@fund.test,1\n"
    );

    $result = app(MemberImportService::class)->import($path, 'TempPass@123');

    expect($result['created'])->toBe(2)
        ->and($result['skipped'])->toBe(1)
        ->and($result['failed'])->toBe(0)
        ->and(Member::query()->count())->toBe(3);
});

test('member import accepts arabic legacy status labels', function () {
    $path = writeMemberImportCsv(
        "member_number,name,email,status\n".
        "AR-ACTIVE,Arabic Active Member,arabic.active@fund.test,مستمر\n".
        "AR-WITHDRAWN,Arabic Withdrawn Member,arabic.withdrawn@fund.test,منسحب\n"
    );

    $result = app(MemberImportService::class)->import($path, 'TempPass@123');

    expect($result['created'])->toBe(2)
        ->and($result['failed'])->toBe(0);

    expect(Member::query()->where('member_number', 'AR-ACTIVE')->value('status'))->toBe('active')
        ->and(Member::query()->where('member_number', 'AR-WITHDRAWN')->value('status'))->toBe('withdrawn');
});

test('member export includes roster and balance columns', function () {
    $member = Member::create([
        'member_number' => 'MEM-EXPORT-01',
        'name' => 'Export Member',
        'email' => 'export.member@fund.test',
        'monthly_contribution_amount' => 1500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $this->accounting->createMemberAccounts($member);
    $member->cashAccount->update(['balance' => 750]);
    $member->fundAccount->update(['balance' => 3200]);

    ob_start();
    app(MemberExportService::class)->downloadCsv()->sendContent();
    $csv = ob_get_clean();

    expect($csv)
        ->toContain('member_number')
        ->toContain('MEM-EXPORT-01')
        ->toContain('export.member@fund.test')
        ->toContain('750')
        ->toContain('3200');
});

test('members list exposes table header import export and create actions', function () {
    $component = Livewire::test(ListMembers::class);

    $names = collect($component->instance()->getTable()->getHeaderActions())
        ->map(fn ($action) => $action->getName())
        ->all();

    expect($names)->toContain('importMembers', 'exportMembers', 'create');
});

test('member workspace exposes membership and arrears header actions', function () {
    $active = Member::create([
        'member_number' => 'MEM-ACTIVE-'.uniqid(),
        'name' => 'Active Row Member',
        'email' => 'active-row@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $inactive = Member::create([
        'member_number' => 'MEM-DELQ-'.uniqid(),
        'name' => 'Inactive Row Member',
        'email' => 'inactive-row@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'inactive',
        'frozen_at' => null,
    ]);

    $this->accounting->createMemberAccounts($active);

    $portalUser = User::create([
        'name' => 'Portal Member',
        'email' => 'portal-member@fund.test',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);
    $active->update(['user_id' => $portalUser->id]);

    Livewire::test(ViewMember::class, ['record' => $active->getRouteKey()])
        ->assertActionVisible('memberApplication')
        ->assertActionVisible('chargeAnnualSubscription')
        ->assertActionVisible('delete')
        ->assertActionVisible('adjustMemberCash')
        ->assertActionVisible('adjustMemberFund')
        ->assertActionVisible('sendMemberMessage')
        ->assertActionVisible('sendMemberNotification')
        ->assertActionVisible('withdrawMember')
        ->assertActionVisible('freezeMember')
        ->assertActionVisible('adminMemberOverride')
        ->assertActionVisible('checkMemberArrears')
        ->callAction('freezeMember', data: [
            'reason' => 'Test freeze',
            'freeze_date' => BusinessDay::today()->toDateString(),
        ])
        ->assertNotified();

    Livewire::test(ViewMember::class, ['record' => $inactive->getRouteKey()])
        ->assertActionVisible('restoreSuspendedMember');

    expect($active->fresh()->status)->toBe('inactive')
        ->and($active->fresh()->frozen_at)->not->toBeNull();
});

test('member freeze and unfreeze header actions update status', function () {
    $member = Member::create([
        'member_number' => 'MEM-FREEZE-FLOW-'.uniqid(),
        'name' => 'Freeze Flow Member',
        'email' => 'freeze-flow@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    Livewire::test(ViewMember::class, ['record' => $member->getRouteKey()])
        ->callAction('freezeMember', data: [
            'reason' => 'Manual admin freeze',
            'freeze_date' => BusinessDay::today()->toDateString(),
        ])
        ->assertNotified();

    expect($member->fresh()->status)->toBe('inactive')
        ->and($member->fresh()->frozen_at)->not->toBeNull();

    Livewire::test(ViewMember::class, ['record' => $member->fresh()->getRouteKey()])
        ->callAction('unfreezeMember')
        ->assertNotified();

    expect($member->fresh()->status)->toBe('active');
});

test('admin override row action creates standing eligibility override', function () {
    $member = Member::create([
        'member_number' => 'MEM-OVERRIDE-ROW-'.uniqid(),
        'name' => 'Override Row Member',
        'email' => 'override-row@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subMonths(3),
        'status' => 'active',
    ]);

    $this->accounting->createMemberAccounts($member);

    Livewire::test(ViewMember::class, ['record' => $member->getRouteKey()])
        ->callAction('adminMemberOverride', data: [
            'gates' => [LoanEligibilityGate::MEMBERSHIP_TENURE],
            'reason' => 'Board approved early loan eligibility.',
        ])
        ->assertNotified();

    expect(LoanEligibilityOverride::query()
        ->where('member_id', $member->id)
        ->where('gate', LoanEligibilityGate::MEMBERSHIP_TENURE)
        ->exists())->toBeTrue();
});

test('members list create action is on table header and page links applications', function () {
    $component = Livewire::test(ListMembers::class);

    $pageHeaderNames = collect($component->instance()->getCachedHeaderActions())
        ->map(fn ($action) => $action->getName())
        ->all();

    expect($pageHeaderNames)->toContain('pending_applications', 'member_requests');

    $tableHeaderActionNames = collect($component->instance()->getTable()->getHeaderActions())
        ->map(fn ($action) => $action->getName())
        ->all();

    expect($tableHeaderActionNames)->toContain('create', 'importMembers', 'exportMembers');
});

test('member import sample download route returns csv', function () {
    $response = $this->get('http://'.$this->domain.'/downloads/member-import-sample');

    $response->assertSuccessful()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    expect($response->streamedContent())
        ->toContain('member_number')
        ->toContain('contribution_arrears_cutoff_date');
});

test('withdraw header action updates status to withdrawn', function () {
    $member = Member::create([
        'member_number' => 'MEM-WD-'.uniqid(),
        'name' => 'Withdraw Flow Member',
        'email' => 'withdraw-flow@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    Livewire::test(ViewMember::class, ['record' => $member->getRouteKey()])
        ->callAction('withdrawMember', data: [
            'reason' => 'Left the fund',
            'hold_payout' => true,
            'withdraw_date' => now()->toDateString(),
        ])
        ->assertNotified();

    expect($member->fresh()->status)->toBe('withdrawn')
        ->and($member->fresh()->payout_frozen_at)->not->toBeNull();
});

test('adjust cash row action posts manual credit', function () {
    $member = Member::create([
        'member_number' => 'MEM-ADJ-CASH-'.uniqid(),
        'name' => 'Adjust Cash Member',
        'email' => 'adjust-cash@fund.test',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $this->accounting->createMemberAccounts($member);

    Livewire::test(ViewMember::class, ['record' => $member->getRouteKey()])
        ->callAction('adjustMemberCash', data: [
            'direction' => 'credit',
            'amount' => 125,
            'description' => 'Manual cash top-up',
            'transacted_at' => now()->toDateTimeString(),
        ])
        ->assertNotified();

    expect((float) $member->cashAccount->fresh()->balance)->toBe(125.0);
});

test('member annual subscription fee service charges configured fee', function () {
    Setting::set('subscription', 'annual_fee', 75);

    $member = Member::create([
        'member_number' => 'MEM-ANNUAL-SVC-'.uniqid(),
        'name' => 'Annual Fee Service Member',
        'email' => 'annual-fee-svc@fund.test',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $this->accounting->createMemberAccounts($member);
    $this->accounting->creditMemberCashWithMasterMirror(
        $member->cashAccount,
        500,
        'Seed',
        __('(seed mirror)'),
        null,
        null,
        $member->id,
    );

    app(MemberAnnualSubscriptionFeeService::class)->charge($member);

    expect((float) $member->cashAccount->fresh()->balance)->toBe(425.0)
        ->and((float) Account::masterFees()->fresh()->balance)->toBe(75.0);
});

test('application header action is visible for dependent members using household head', function () {
    $parent = Member::create([
        'member_number' => 'MEM-APP-PARENT-'.uniqid(),
        'name' => 'Application Parent',
        'email' => 'application-parent@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $dependent = Member::create([
        'member_number' => 'MEM-APP-DEP-'.uniqid(),
        'name' => 'Application Dependent',
        'email' => 'application-dependent@fund.test',
        'parent_member_id' => $parent->id,
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    Livewire::test(ViewMember::class, ['record' => $dependent->getRouteKey()])
        ->assertActionVisible('memberApplication');

    expect($dependent->householdHead()->is($parent))->toBeTrue();
});

test('charge annual subscription header action is visible when fee is configured', function () {
    Setting::set('subscription', 'annual_fee', 75);

    $member = Member::create([
        'member_number' => 'MEM-ANNUAL-'.uniqid(),
        'name' => 'Annual Fee Member',
        'email' => 'annual-fee@fund.test',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    Livewire::test(ViewMember::class, ['record' => $member->getRouteKey()])
        ->assertActionVisible('chargeAnnualSubscription');
});

test('charge annual subscription header action is visible even when fee is not configured', function () {
    Setting::set('subscription', 'annual_fee', 0);

    $member = Member::create([
        'member_number' => 'MEM-ANNUAL-ZERO-'.uniqid(),
        'name' => 'Annual Fee Zero Member',
        'email' => 'annual-fee-zero@fund.test',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    Livewire::test(ViewMember::class, ['record' => $member->getRouteKey()])
        ->assertActionVisible('chargeAnnualSubscription');
});

test('charge annual subscription service rejects insufficient cash', function () {
    Setting::set('subscription', 'annual_fee', 100);

    $member = Member::create([
        'member_number' => 'MEM-ANNUAL-FAIL-'.uniqid(),
        'name' => 'Annual Fee Fail Member',
        'email' => 'annual-fee-fail@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $this->accounting->createMemberAccounts($member);

    app(MemberAnnualSubscriptionFeeService::class)->charge($member);
})->throws(InvalidArgumentException::class);

test('repayment header action is visible for members under loan repayment', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-06'));

    $member = Member::create([
        'member_number' => 'MEM-REPAY-'.uniqid(),
        'name' => 'Repayment Row Member',
        'email' => 'repayment-row@fund.test',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);

    $withoutLoan = Member::create([
        'member_number' => 'MEM-NO-LOAN-'.uniqid(),
        'name' => 'No Loan Member',
        'email' => 'no-loan@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $this->accounting->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 12_000,
        'amount_requested' => 12_000,
        'amount_approved' => 12_000,
        'amount_disbursed' => 12_000,
        'interest_rate' => 10,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2026-01-01'),
        'disbursed_at' => Carbon::parse('2026-01-01'),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::parse('2026-03-05'),
        'status' => 'pending',
    ]);

    AccountingService::withoutMemberCashCollection(
        fn () => $this->accounting->credit($member->cashAccount, 2000, 'Deposit'),
    );

    Livewire::test(ViewMember::class, ['record' => $member->getRouteKey()])
        ->assertActionVisible('memberLoanRepayment');

    Livewire::test(ViewMember::class, ['record' => $withoutLoan->getRouteKey()])
        ->assertActionHidden('memberLoanRepayment');

    Carbon::setTestNow();
});

test('members list exposes bulk actions for member and delinquency workflows', function () {
    $component = Livewire::test(ListMembers::class);

    $names = collect($component->instance()->getTable()->getFlatBulkActions())
        ->map(fn ($action) => $action->getName())
        ->all();

    expect($names)->toContain(
        'contributeSelectedMembers',
        'repaySelectedMembers',
        'adjustCashSelectedMembers',
        'adjustFundSelectedMembers',
        'sendMessageSelectedMembers',
        'sendNotificationSelectedMembers',
        'freezeSelectedMembers',
        'restoreSuspendedSelectedMembers',
        'withdrawSelectedMembers',
        'chargeAnnualSubscriptionSelectedMembers',
        'adminOverrideSelectedMembers',
        'delete',
        'checkArrearsSelected',
    );
});

test('send notification row action posts portal notification', function () {
    $member = Member::create([
        'member_number' => 'MEM-NOTIFY-'.uniqid(),
        'name' => 'Notify Row Member',
        'email' => 'notify-row@fund.test',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $portalUser = User::create([
        'name' => 'Notify Portal User',
        'email' => 'notify-portal@fund.test',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);
    $member->update(['user_id' => $portalUser->id]);

    Livewire::test(ViewMember::class, ['record' => $member->getRouteKey()])
        ->callAction('sendMemberNotification', data: [
            'title' => 'Fund reminder',
            'body' => 'Please review your account balance.',
        ])
        ->assertNotified();

    expect($portalUser->notifications()->count())->toBe(1);
});
