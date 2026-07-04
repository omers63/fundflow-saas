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

uses(InitializesTenancy::class);

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
        'joined_at' => Carbon::parse('2026-05-01'),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);
});

test('member contribution stat cards include cycle forecast fields', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $cards = app(MemberContributionInsightsService::class)->statCards($this->member);
    $labels = collect($cards)->pluck('label');

    expect($labels)->toContain(__('Days left'), __('Cash gap'));

    Carbon::setTestNow();
});

test('member contribution insights snapshot includes cycle and trend data', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $cycles = app(ContributionCycleService::class);
    [$openMonth, $openYear] = $cycles->currentOpenPeriod();
    $openPeriod = Contribution::periodDate($openMonth, $openYear);

    $previousCursor = Carbon::create($openYear, $openMonth, 1)->subMonthNoOverflow();
    $previousPeriod = Contribution::periodDate((int) $previousCursor->month, (int) $previousCursor->year);

    Contribution::factory()->for($this->member)->posted()->create([
        'period' => $openPeriod,
        'amount' => 1000,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'is_late' => false,
    ]);

    Contribution::factory()->for($this->member)->posted()->create([
        'period' => $previousPeriod,
        'amount' => 1000,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'is_late' => false,
    ]);

    $snapshot = app(MemberContributionInsightsService::class)->snapshot($this->member);

    expect($snapshot)->toHaveKeys(['hero', 'kpis', 'open_cycle', 'trend', 'consistency', 'streak', 'summary'])
        ->and($snapshot['kpis'])->toHaveCount(6)
        ->and($snapshot['trend'])->toHaveCount(6)
        ->and($snapshot['open_cycle']['period_label'])->not->toBeEmpty()
        ->and($snapshot['summary']['pending_count'])->toBe(0)
        ->and($snapshot['summary']['posted_count'])->toBe(2)
        ->and($snapshot['consistency']['posted'])->toBe(2)
        ->and($snapshot['consistency']['liable'])->toBe(2)
        ->and($snapshot['streak'])->toBe(2);

    Carbon::setTestNow();
});

test('member contribution insights returns empty array without member', function () {
    expect(app(MemberContributionInsightsService::class)->snapshot(null))->toBe([]);
});

test('on-time rate counts pre-loan cycles when member is under active loan repayment', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    Loan::query()->delete();

    $this->member->update(['joined_at' => Carbon::parse('2026-02-01')]);

    $cycles = app(ContributionCycleService::class);
    [$openMonth, $openYear] = $cycles->currentOpenPeriod();
    $march = Carbon::create(2026, 3, 1)->startOfMonth();
    $february = Carbon::create(2026, 2, 1)->startOfMonth();

    Contribution::factory()->for($this->member)->posted()->create([
        'period' => Contribution::periodDate((int) $march->month, (int) $march->year),
        'amount' => 1000,
        'is_late' => false,
    ]);

    Contribution::factory()->for($this->member)->posted()->create([
        'period' => Contribution::periodDate((int) $february->month, (int) $february->year),
        'amount' => 1000,
        'is_late' => false,
    ]);

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
        'has_grace_cycle' => false,
        'applied_at' => Carbon::parse('2026-04-01'),
        'disbursed_at' => Carbon::parse('2026-04-01'),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::parse('2026-06-05'),
        'status' => 'pending',
    ]);

    expect($this->member->fresh()->isExemptFromContributions())->toBeTrue()
        ->and($this->member->fresh()->isExemptFromContributions((int) $march->month, (int) $march->year))->toBeFalse()
        ->and($this->member->fresh()->isExemptFromContributions($openMonth, $openYear))->toBeTrue();

    $consistency = app(MemberContributionInsightsService::class)->snapshot($this->member->fresh())['consistency'];

    expect($consistency['posted'])->toBe(2)
        ->and($consistency['liable'])->toBe(2);

    Carbon::setTestNow();
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
