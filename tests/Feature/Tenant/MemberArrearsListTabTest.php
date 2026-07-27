<?php

declare(strict_types=1);

use App\Filament\Support\MemberArrearsInventory;
use App\Filament\Tenant\Resources\Members\Pages\ListMembers;
use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanDelinquencyService;
use App\Services\Tenant\MemberListTabService;
use App\Support\CollectionInsightsCache;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    app()->setLocale('en');

    CollectionInsightsCache::bumpAll();

    Account::query()->delete();
    Member::query()->delete();
    Loan::query()->delete();
    LoanInstallment::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);

    $this->actingAs(User::create([
        'name' => 'Arrears Tab Admin',
        'email' => 'arrears-tab-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]), 'tenant');

    $this->accounting = app(AccountingService::class);
    $this->cycles = app(ContributionCycleService::class);
    $this->delinquency = app(LoanDelinquencyService::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('arrears tab includes members with unpaid emis from any past cycle', function () {
    [$openMonth, $openYear] = $this->cycles->currentOpenPeriod();
    $previous = Carbon::create($openYear, $openMonth, 1)->subMonthNoOverflow();

    $owing = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
    ]);
    $this->accounting->createMemberAccounts($owing);

    $clear = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
    ]);
    $this->accounting->createMemberAccounts($clear);

    $loan = Loan::create([
        'member_id' => $owing->id,
        'amount' => 6000,
        'amount_requested' => 6000,
        'amount_approved' => 6000,
        'amount_disbursed' => 6000,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2024-01-01'),
        'disbursed_at' => Carbon::parse('2024-01-01'),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::create((int) $previous->year, (int) $previous->month, 10),
        'status' => 'pending',
    ]);

    $ids = app(MemberListTabService::class)->outstandingArrearsMemberIds();

    expect($ids)->toContain($owing->id)
        ->and($ids)->not->toContain($clear->id)
        ->and($this->delinquency->membersWithOutstandingArrearsIds())->toContain($owing->id);

    Livewire::withQueryParams(['tab' => 'delinquent'])
        ->test(ListMembers::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$owing])
        ->assertCanNotSeeTableRecords([$clear])
        ->assertSee(__('Members with contribution or loan EMI arrears for any labelled cycle. Open a row to review each unpaid period.'), false);
});

test('arrears row inventory lists contribution and emi items for the member', function () {
    Setting::set('contribution', 'cycle_start_day', '1');

    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    [$openMonth, $openYear] = $this->cycles->currentOpenPeriod();
    $previous = Carbon::create($openYear, $openMonth, 1)->subMonthNoOverflow();

    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'contribution_arrears_cutoff_date' => Carbon::parse('2024-01-01'),
    ]);
    $this->accounting->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 6000,
        'amount_requested' => 6000,
        'amount_approved' => 6000,
        'amount_disbursed' => 6000,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2024-01-01'),
        'disbursed_at' => Carbon::parse('2024-01-01'),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::create((int) $previous->year, (int) $previous->month, 10),
        'status' => 'overdue',
    ]);

    $inventory = $this->delinquency->memberArrearsInventory($member);

    expect($inventory)->not->toBeEmpty()
        ->and(collect($inventory)->contains(fn (array $row): bool => $row['type'] === 'emi'))->toBeTrue();

    $html = MemberArrearsInventory::modalContent($member)->toHtml();

    expect($html)->toContain(__('Loan EMI'))
        ->and($html)->toContain(__('Cycle'));

    Livewire::withQueryParams(['tab' => 'delinquent'])
        ->test(ListMembers::class)
        ->assertSuccessful()
        ->assertTableActionExists('viewArrears')
        ->mountTableAction('viewArrears', $member);
});
