<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanDelinquencyService;
use App\Services\MemberInsightsService;
use App\Services\Tenant\MemberListTabService;
use App\Support\BusinessDay;
use App\Support\CollectionInsightsCache;
use App\Support\TenantRuntimeCache;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    CollectionInsightsCache::bump(CollectionInsightsCache::DOMAIN_MEMBERS);
    TenantRuntimeCache::forget('loan_delinquency:members_with_outstanding_arrears_ids');
    TenantRuntimeCache::forget('loan_delinquency:delinquent_member_ids');

    Account::query()->delete();
    Member::query()->delete();
    Loan::query()->delete();
    LoanInstallment::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('insights snapshot aggregates member roster metrics', function () {
    Member::factory()->create([
        'name' => 'Active One',
        'status' => 'active',
        'monthly_contribution_amount' => 0,
        'joined_at' => BusinessDay::now(),
    ]);

    Member::factory()->create([
        'name' => 'Roster Two',
        'status' => 'active',
        'monthly_contribution_amount' => 0,
        'joined_at' => BusinessDay::now()->subMonths(2),
    ]);

    $parent = Member::factory()->create([
        'name' => 'Parent',
        'status' => 'active',
        'monthly_contribution_amount' => 0,
    ]);

    Member::factory()->create([
        'name' => 'Dependent',
        'status' => 'active',
        'monthly_contribution_amount' => 0,
        'parent_member_id' => $parent->id,
    ]);

    $snapshot = app(MemberInsightsService::class)->snapshot();

    expect($snapshot['total'])->toBe(4)
        ->and($snapshot['active'])->toBe(4)
        ->and($snapshot['delinquent'])->toBe(0)
        ->and($snapshot['migration_pending'])->toBe(0)
        ->and($snapshot['dependents'])->toBe(1)
        ->and($snapshot['new_this_month'])->toBe(1)
        ->and($snapshot['status_breakdown'])->toHaveCount(3)
        ->and($snapshot['trend'])->toHaveCount(6)
        ->and($snapshot['sparkline'])->toHaveCount(8);
});

test('insights attention queue excludes withdrawn members', function () {
    Member::factory()->create([
        'name' => 'Withdrawn Member',
        'status' => 'withdrawn',
        'monthly_contribution_amount' => 500,
    ]);

    Member::factory()->create([
        'name' => 'Inactive Member',
        'status' => 'inactive',
        'monthly_contribution_amount' => 750,
    ]);

    $snapshot = app(MemberInsightsService::class)->snapshot();
    $queueNames = collect($snapshot['attention_queue'])->pluck('name')->all();

    expect($queueNames)->toContain('Inactive Member')
        ->and($queueNames)->not->toContain('Withdrawn Member');
});

test('insights arrears count matches outstanding arrears tab not policy-only delinquents', function () {
    $cycles = app(ContributionCycleService::class);
    $accounting = app(AccountingService::class);
    $delinquency = app(LoanDelinquencyService::class);

    [$openMonth, $openYear] = $cycles->currentOpenPeriod();
    $previous = Carbon::create($openYear, $openMonth, 1)->subMonthNoOverflow();

    $owing = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
    ]);
    $accounting->createMemberAccounts($owing);

    $clear = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
    ]);
    $accounting->createMemberAccounts($clear);

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

    $tabArrears = count(app(MemberListTabService::class)->outstandingArrearsMemberIds());
    $outstanding = count($delinquency->membersWithOutstandingArrearsIds());
    $policyOnly = count($delinquency->delinquentMemberIds());

    $snapshot = app(MemberInsightsService::class)->snapshot();

    expect($outstanding)->toBe($tabArrears)
        ->and($snapshot['delinquent'])->toBe($tabArrears)
        ->and($snapshot['delinquent'])->toBeGreaterThan($policyOnly)
        ->and($snapshot['pipeline']['delinquent_members'])->toBe($tabArrears)
        ->and(collect($snapshot['attention_queue'])->pluck('id')->all())->toContain($owing->id)
        ->and(collect($snapshot['attention_queue'])->firstWhere('id', $owing->id)['has_arrears'] ?? false)->toBeTrue();
});

test('insights migration pending matches roster tab counts', function () {
    Member::factory()->create([
        'status' => 'active',
        'opening_balances_posted_at' => now(),
        'contribution_arrears_cutoff_date' => now()->subMonths(3)->startOfMonth(),
        'joined_at' => now()->subMonths(6),
        'monthly_contribution_amount' => 500,
    ]);

    Member::factory()->create([
        'status' => 'active',
        'opening_balances_posted_at' => now(),
        'joined_at' => now()->subMonth(),
        'monthly_contribution_amount' => 500,
    ]);

    $tabs = app(MemberListTabService::class)->tabCounts();
    $snapshot = app(MemberInsightsService::class)->snapshot();

    expect($snapshot['migration_pending'])->toBe($tabs['migration_pending'])
        ->and($snapshot['migration_pending'])->toBeGreaterThan(0)
        ->and($snapshot['total'])->toBe($tabs['all'])
        ->and($snapshot['active'])->toBe($tabs['active']);
});
