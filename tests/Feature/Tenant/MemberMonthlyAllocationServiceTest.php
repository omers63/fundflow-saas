<?php

declare(strict_types=1);

use App\Filament\Member\Pages\MyContributionSettingsPage;
use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanDelinquencyService;
use App\Services\MemberMonthlyAllocationService;
use App\Support\BusinessDaySettings;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    Loan::query()->delete();
    LoanInstallment::query()->delete();
    User::query()->delete();

    $this->allocations = app(MemberMonthlyAllocationService::class);
    $this->delinquency = app(LoanDelinquencyService::class);
    $this->accounting = app(AccountingService::class);
});

afterEach(function () {
    Carbon::setTestNow();
    BusinessDaySettings::saveFromForm(null);
});

test('member can change allocation when only the open cycle contribution is unpaid', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));
    BusinessDaySettings::saveFromForm('2026-06-15');

    $member = Member::create([
        'member_number' => 'MEM-OPEN-CYCLE',
        'name' => 'Open Cycle Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => Carbon::parse('2024-06-01'),
        'contribution_arrears_cutoff_date' => Carbon::parse('2024-06-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $cycles = app(ContributionCycleService::class);
    [$openMonth, $openYear] = $cycles->currentOpenPeriod();
    $cursor = Carbon::parse('2024-06-01')->startOfMonth();
    $openStart = Carbon::create($openYear, $openMonth, 1)->startOfMonth();

    while ($cursor->lt($openStart)) {
        Contribution::create([
            'member_id' => $member->id,
            'period' => Contribution::periodDate((int) $cursor->month, (int) $cursor->year),
            'amount' => 1000,
            'status' => 'posted',
            'posted_at' => $cursor->copy(),
        ]);
        $cursor->addMonthNoOverflow();
    }

    expect($this->delinquency->memberHasArrearsExcludingOpenCycle($member))->toBeFalse()
        ->and($this->allocations->canChangeMonthlyContribution($member))->toBeTrue();

    $member->update(['monthly_contribution_amount' => 1500]);

    expect((int) $member->fresh()->monthly_contribution_amount)->toBe(1500);
});

test('independent member cannot change allocation while they have repayment arrears', function () {
    $member = Member::create([
        'member_number' => 'MEM-IND-1',
        'name' => 'Independent Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    LoanInstallment::create([
        'loan_id' => Loan::create([
            'member_id' => $member->id,
            'amount' => 5000,
            'amount_requested' => 5000,
            'amount_approved' => 5000,
            'amount_disbursed' => 5000,
            'interest_rate' => 10,
            'term_months' => 6,
            'monthly_repayment' => 1000,
            'total_repaid' => 0,
            'status' => 'active',
            'applied_at' => now()->subMonths(3),
            'disbursed_at' => now()->subMonths(3),
        ])->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => now()->subMonths(2)->startOfMonth(),
        'status' => 'overdue',
        'is_late' => true,
    ]);

    expect($this->allocations->canChangeMonthlyContribution($member))->toBeFalse();

    expect(fn () => $member->update(['monthly_contribution_amount' => 1500]))
        ->toThrow(InvalidArgumentException::class);
});

test('parent cannot change allocation when a dependent has contribution arrears', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $parent = Member::create([
        'member_number' => 'MEM-P-ALLOC',
        'name' => 'Parent Alloc',
        'email' => 'parent-alloc@example.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => Carbon::parse('2024-06-01'),
        'contribution_arrears_cutoff_date' => Carbon::parse('2024-06-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($parent);

    $dependent = Member::create([
        'member_number' => 'MEM-D-ALLOC',
        'name' => 'Child Alloc',
        'email' => 'child-alloc@example.test',
        'parent_member_id' => $parent->id,
        'household_email' => 'parent-alloc@example.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-06-01'),
        'contribution_arrears_cutoff_date' => Carbon::parse('2024-06-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($dependent);

    expect($this->allocations->householdHasUnpaidArrears($parent))->toBeTrue()
        ->and($this->allocations->canChangeMonthlyContribution($parent))->toBeFalse()
        ->and($this->allocations->canChangeMonthlyContribution($dependent))->toBeFalse();

    expect(fn () => $parent->update(['monthly_contribution_amount' => 1500]))
        ->toThrow(InvalidArgumentException::class);

    Carbon::setTestNow();
});

test('household can change allocation after all arrears are cleared', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));
    BusinessDaySettings::saveFromForm('2026-06-15');

    $member = Member::create([
        'member_number' => 'MEM-CLEAR',
        'name' => 'Clear Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => Carbon::parse('2026-06-01'),
        'contribution_arrears_cutoff_date' => Carbon::parse('2026-06-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    expect($this->allocations->canChangeMonthlyContribution($member))->toBeTrue();

    $member->update(['monthly_contribution_amount' => 1500]);

    expect((int) $member->fresh()->monthly_contribution_amount)->toBe(1500);

    Carbon::setTestNow();
    BusinessDaySettings::saveFromForm(null);
});

test('member portal blocks saving allocation while household has arrears', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $user = User::create([
        'name' => 'Portal Parent',
        'email' => 'portal-parent@example.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $member = Member::create([
        'user_id' => $user->id,
        'member_number' => 'MEM-PORTAL-P',
        'name' => 'Portal Parent',
        'email' => 'portal-parent@example.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => Carbon::parse('2024-06-01'),
        'contribution_arrears_cutoff_date' => Carbon::parse('2024-06-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    Member::create([
        'member_number' => 'MEM-PORTAL-D',
        'name' => 'Portal Child',
        'email' => 'child@example.test',
        'parent_member_id' => $member->id,
        'household_email' => 'portal-parent@example.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-06-01'),
        'contribution_arrears_cutoff_date' => Carbon::parse('2024-06-01'),
        'status' => 'active',
    ]);

    $this->actingAs($user, 'tenant');
    Filament::setCurrentPanel('member');

    Livewire::test(MyContributionSettingsPage::class)
        ->assertSet('allocationChangeBlocked', true)
        ->assertActionDisabled('save_allocation')
        ->assertSee(__('Allocation locked'));

    expect((int) $member->fresh()->monthly_contribution_amount)->toBe(1000);

    Carbon::setTestNow();
});
