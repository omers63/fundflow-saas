<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\MemberContributionInsightsService;
use App\Support\Insights\InsightFormatter;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('member');

    Account::query()->delete();
    Contribution::query()->delete();
    Member::query()->delete();
    User::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);

    $this->memberUser = User::create([
        'name' => 'Contrib Member',
        'email' => 'contrib@insights.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-CNT01',
        'name' => 'Contrib Member',
        'email' => 'contrib@insights.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);
});

test('member contribution insights snapshot includes cycle and trend data', function () {
    [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();
    $period = Contribution::periodDate($month, $year);

    Contribution::factory()->for($this->member)->create([
        'period' => $period,
        'amount' => 1000,
        'status' => 'pending',
        'is_late' => false,
    ]);

    Contribution::factory()->for($this->member)->posted()->create([
        'period' => now()->subMonths(2)->startOfMonth()->toDateString(),
        'amount' => 1000,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
    ]);

    $snapshot = app(MemberContributionInsightsService::class)->snapshot($this->member);

    expect($snapshot)->toHaveKeys(['hero', 'kpis', 'open_cycle', 'trend', 'consistency', 'streak', 'summary'])
        ->and($snapshot['kpis'])->toHaveCount(6)
        ->and($snapshot['trend'])->toHaveCount(6)
        ->and($snapshot['open_cycle']['period_label'])->not->toBeEmpty()
        ->and($snapshot['summary']['pending_count'])->toBe(1)
        ->and($snapshot['summary']['posted_count'])->toBe(1);
});

test('member contribution insights returns empty array without member', function () {
    expect(app(MemberContributionInsightsService::class)->snapshot(null))->toBe([]);
});

test('open cycle shows loan repayment instead of required cash during active loan', function () {
    $loan = Loan::create([
        'member_id' => $this->member->id,
        'amount' => 12_000,
        'amount_requested' => 12_000,
        'amount_approved' => 12_000,
        'amount_disbursed' => 12_000,
        'interest_rate' => 10,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2025-06-01'),
        'disbursed_at' => Carbon::parse('2025-06-01'),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::parse('2026-03-05'),
        'status' => 'pending',
    ]);

    $open = app(MemberContributionInsightsService::class)->snapshot($this->member)['open_cycle'];

    expect($open['under_loan_repayment'])->toBeTrue()
        ->and($open['status_key'])->toBe('loan_repayment')
        ->and($open['status_label'])->toBe(__('Under loan repayment'))
        ->and($open['required_cash'])->toBe(InsightFormatter::money(0));
});
