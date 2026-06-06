<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Members\Pages\ListMembers;
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

test('members list exposes legacy row actions and delinquency actions', function () {
    $active = Member::create([
        'member_number' => 'MEM-ACTIVE-'.uniqid(),
        'name' => 'Active Row Member',
        'email' => 'active-row@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $delinquent = Member::create([
        'member_number' => 'MEM-DELQ-'.uniqid(),
        'name' => 'Delinquent Row Member',
        'email' => 'delinquent-row@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'delinquent',
    ]);

    $suspended = Member::create([
        'member_number' => 'MEM-SUSP-'.uniqid(),
        'name' => 'Suspended Row Member',
        'email' => 'suspended-row@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'suspended',
    ]);

    $this->accounting->createMemberAccounts($active);

    $portalUser = User::create([
        'name' => 'Portal Member',
        'email' => 'portal-member@fund.test',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);
    $active->update(['user_id' => $portalUser->id]);

    Livewire::test(ListMembers::class)
        ->assertTableActionVisible('view', $active)
        ->assertTableActionVisible('edit', $active)
        ->assertTableActionVisible('memberApplication', $active)
        ->assertTableActionVisible('chargeAnnualSubscription', $active)
        ->assertTableActionVisible('delete', $active)
        ->assertTableActionVisible('adjustMemberCash', $active)
        ->assertTableActionVisible('adjustMemberFund', $active)
        ->assertTableActionVisible('sendMemberMessage', $active)
        ->assertTableActionVisible('sendMemberNotification', $active)
        ->assertTableActionVisible('terminateMember', $active)
        ->assertTableActionVisible('suspendMember', $active)
        ->assertTableActionVisible('adminMemberOverride', $active)
        ->assertTableActionVisible('syncMemberDelinquency', $active)
        ->assertTableActionVisible('markMemberDelinquent', $active)
        ->assertTableActionVisible('restoreMemberActive', $delinquent)
        ->assertTableActionVisible('restoreSuspendedMember', $suspended)
        ->callTableAction('markMemberDelinquent', $active)
        ->assertNotified();

    expect($active->fresh()->status)->toBe('delinquent');
});

test('member suspend and restore row actions update status', function () {
    $member = Member::create([
        'member_number' => 'MEM-SUSP-FLOW-'.uniqid(),
        'name' => 'Suspend Flow Member',
        'email' => 'suspend-flow@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    Livewire::test(ListMembers::class)
        ->callTableAction('suspendMember', $member, data: ['reason' => 'Manual admin suspension'])
        ->assertNotified();

    expect($member->fresh()->status)->toBe('suspended');

    Livewire::test(ListMembers::class)
        ->callTableAction('restoreSuspendedMember', $member->fresh())
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

    Livewire::test(ListMembers::class)
        ->callTableAction('adminMemberOverride', $member, data: [
            'gates' => [LoanEligibilityGate::MEMBERSHIP_TENURE],
            'reason' => 'Board approved early loan eligibility.',
        ])
        ->assertNotified();

    expect(LoanEligibilityOverride::query()
        ->where('member_id', $member->id)
        ->where('gate', LoanEligibilityGate::MEMBERSHIP_TENURE)
        ->exists())->toBeTrue();
});

test('members list create action is on table header not page header', function () {
    $component = Livewire::test(ListMembers::class);

    $method = new ReflectionMethod(ListMembers::class, 'getHeaderActions');
    $method->setAccessible(true);

    expect($method->invoke($component->instance()))->toBe([]);

    $headerActionNames = collect($component->instance()->getTable()->getHeaderActions())
        ->map(fn ($action) => $action->getName())
        ->all();

    expect($headerActionNames)->toContain('create', 'importMembers', 'exportMembers');
});

test('member import sample download route returns csv', function () {
    $response = $this->get('http://'.$this->domain.'/downloads/member-import-sample');

    $response->assertSuccessful()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    expect($response->streamedContent())
        ->toContain('member_number')
        ->toContain('contribution_arrears_cutoff_date');
});

test('terminate member row action updates status', function () {
    $member = Member::create([
        'member_number' => 'MEM-TERM-'.uniqid(),
        'name' => 'Terminate Flow Member',
        'email' => 'terminate-flow@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    Livewire::test(ListMembers::class)
        ->callTableAction('terminateMember', $member, data: ['reason' => 'Left the fund'])
        ->assertNotified();

    expect($member->fresh()->status)->toBe('terminated');
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

    Livewire::test(ListMembers::class)
        ->callTableAction('adjustMemberCash', $member, data: [
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

test('application row action is visible for dependent members using household head', function () {
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

    Livewire::test(ListMembers::class)
        ->assertTableActionVisible('memberApplication', $dependent);

    expect($dependent->householdHead()->is($parent))->toBeTrue();
});

test('charge annual subscription row action is visible when fee is configured', function () {
    Setting::set('subscription', 'annual_fee', 75);

    $member = Member::create([
        'member_number' => 'MEM-ANNUAL-'.uniqid(),
        'name' => 'Annual Fee Member',
        'email' => 'annual-fee@fund.test',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    Livewire::test(ListMembers::class)
        ->assertTableActionVisible('chargeAnnualSubscription', $member);
});

test('charge annual subscription row action is visible even when fee is not configured', function () {
    Setting::set('subscription', 'annual_fee', 0);

    $member = Member::create([
        'member_number' => 'MEM-ANNUAL-ZERO-'.uniqid(),
        'name' => 'Annual Fee Zero Member',
        'email' => 'annual-fee-zero@fund.test',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    Livewire::test(ListMembers::class)
        ->assertTableActionVisible('chargeAnnualSubscription', $member);
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

test('repayment row action is visible for members under loan repayment', function () {
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

    Livewire::test(ListMembers::class)
        ->assertTableActionVisible('memberLoanRepayment', $member)
        ->assertTableActionHidden('memberLoanRepayment', $withoutLoan)
        ->callTableAction('memberLoanRepayment', $member)
        ->assertNotified();

    expect(LoanInstallment::query()->where('loan_id', $loan->id)->value('status'))->toBe('paid');

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
        'suspendSelectedMembers',
        'restoreSuspendedSelectedMembers',
        'terminateSelectedMembers',
        'chargeAnnualSubscriptionSelectedMembers',
        'adminOverrideSelectedMembers',
        'delete',
        'syncDelinquencySelected',
        'markDelinquentSelected',
        'restoreActiveSelected',
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

    Livewire::test(ListMembers::class)
        ->callTableAction('sendMemberNotification', $member, data: [
            'title' => 'Fund reminder',
            'body' => 'Please review your account balance.',
        ])
        ->assertNotified();

    expect($portalUser->notifications()->count())->toBe(1);
});
