<?php

use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Models\Tenant\Account;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanEligibilityOverrideRequest;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\LoanInsightsService;
use App\Services\Loans\LoanEligibilityOverrideRequestService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    $this->service = app(LoanInsightsService::class);

    Loan::query()->delete();
    FundTier::query()->delete();
    LoanTier::query()->delete();
    Member::query()->delete();

    Account::query()->delete();
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 100_000, 'is_master' => true]);
});

test('portfolio snapshot returns pipeline counts and hero', function () {
    $member = Member::create([
        'member_number' => 'MEM-'.uniqid(),
        'name' => 'Test Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);

    Loan::create([
        'member_id' => $member->id,
        'amount' => 5000,
        'amount_requested' => 5000,
        'interest_rate' => 10,
        'term_months' => 12,
        'status' => 'pending',
        'applied_at' => now(),
    ]);

    $snapshot = $this->service->portfolioSnapshot();

    expect($snapshot)->toHaveKeys(['hero', 'kpis', 'pipeline', 'trend', 'currency'])
        ->and($snapshot['pipeline']['needs_decision'])->toBe(1)
        ->and($snapshot['hero']['tone'])->toBe('amber');
});

test('loan detail snapshot includes stepper and relation summaries', function () {
    $member = Member::create([
        'member_number' => 'MEM-'.uniqid(),
        'name' => 'Test Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 8000,
        'amount_requested' => 8000,
        'amount_approved' => 8000,
        'interest_rate' => 10,
        'term_months' => 12,
        'status' => 'approved',
        'applied_at' => now()->subWeek(),
        'approved_at' => now(),
    ]);

    $snapshot = $this->service->loanDetailSnapshot($loan);

    expect($snapshot)->toHaveKeys(['steps', 'snapshot', 'next_due', 'guarantor'])
        ->and($snapshot['steps'])->not->toBeEmpty()
        ->and(collect($snapshot['steps'])->pluck('key'))->toContain('under_review')
        ->and($snapshot['snapshot'])->toHaveKeys(['requested', 'approved', 'disburse_percent', 'repay_percent']);
});

test('loan detail snapshot includes imported repayments summary when legacy rows exist', function () {
    $member = Member::factory()->create(['status' => 'active']);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 8000,
        'amount_requested' => 8000,
        'amount_approved' => 8000,
        'interest_rate' => 10,
        'term_months' => 12,
        'status' => 'approved',
        'applied_at' => now()->subWeek(),
        'approved_at' => now(),
    ]);

    $loan->repayments()->create([
        'amount' => 500,
        'paid_at' => now(),
        'notes' => 'Imported row',
    ]);

    $snapshot = $this->service->loanDetailSnapshot($loan->fresh());

    expect($snapshot['snapshot']['legacy_repayment_total'])->toBe(500.0);
});

test('emi collect snapshot reports pending members and preview', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $cycles = app(ContributionCycleService::class);
    [$month, $year] = $cycles->currentOpenPeriod();

    $member = Member::create([
        'member_number' => 'EMI-INS-1',
        'name' => 'EMI Insights Borrower',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    app(AccountingService::class)->createMemberAccounts($member);

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
        'applied_at' => Carbon::parse('2026-01-01'),
        'disbursed_at' => Carbon::parse('2026-01-01'),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::create($year, $month, 10),
        'status' => 'pending',
    ]);

    $snapshot = $this->service->forContext('emi_collect');

    expect($snapshot)->toHaveKeys(['hero', 'kpis', 'open_period', 'collection_amounts'])
        ->and($snapshot['hero']['tone'])->toBe('amber')
        ->and($snapshot['collection_amounts']['unrecovered_amount'])->toBe(1000.0)
        ->and($snapshot['collection_amounts']['recovered_amount'])->toBe(0.0);

    Carbon::setTestNow();
});

test('emi collected snapshot reports paid installments for open period', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $cycles = app(ContributionCycleService::class);
    [$month, $year] = $cycles->currentOpenPeriod();

    $member = Member::create([
        'member_number' => 'EMI-INS-2',
        'name' => 'EMI Collected Borrower',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    app(AccountingService::class)->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 6000,
        'amount_requested' => 6000,
        'amount_approved' => 6000,
        'amount_disbursed' => 6000,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 1000,
        'total_repaid' => 1000,
        'status' => 'active',
        'applied_at' => Carbon::parse('2026-01-01'),
        'disbursed_at' => Carbon::parse('2026-01-01'),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::create($year, $month, 10),
        'status' => 'paid',
        'paid_at' => Carbon::parse('2026-06-12'),
    ]);

    $snapshot = $this->service->forContext('emi_collected');

    expect($snapshot)->toHaveKeys(['hero', 'kpis', 'open_period', 'collection_amounts'])
        ->and($snapshot['pipeline']['collected_open_period'] ?? null)->toBeNull()
        ->and($snapshot['hero']['tone'])->toBe('success')
        ->and($snapshot['collection_amounts']['recovered_amount'])->toBe(1000.0)
        ->and($snapshot['collection_amounts']['unrecovered_amount'])->toBe(0.0);

    Carbon::setTestNow();
});

test('emi arrears snapshot reports unpaid installments before selected cycle', function () {
    Carbon::setTestNow(Carbon::parse('2025-10-15'));

    Setting::set('contribution', 'cycle_start_day', '6');

    $member = Member::create([
        'member_number' => 'EMI-INS-ARR',
        'name' => 'EMI Arrears Insights Borrower',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    app(AccountingService::class)->createMemberAccounts($member);

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
        'due_date' => Carbon::parse('2025-09-05'),
        'status' => 'pending',
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 2,
        'amount' => 1000,
        'due_date' => Carbon::parse('2025-11-05'),
        'status' => 'pending',
    ]);

    $snapshot = $this->service->forContext('emi_arrears');

    expect($snapshot)->toHaveKeys(['hero', 'kpis', 'open_period', 'collection_amounts'])
        ->and($snapshot['hero']['tone'])->toBe('danger')
        ->and($snapshot['collection_amounts']['arrears_amount'])->toBe(1000.0);

    $html = view('filament.tenant.widgets.loans.emi_arrears', ['d' => $snapshot])->render();

    expect($html)->toContain('ff-app-insights')
        ->and($html)->toContain(__('Total arrears amount'));

    Carbon::setTestNow();
});

test('eligibility reviews snapshot reports pending pipeline and preview', function () {
    if (! LoanEligibilityOverrideRequest::isTableReady()) {
        $this->markTestSkipped('loan_eligibility_override_requests table not migrated');
    }

    $accounting = app(AccountingService::class);
    $memberUser = User::create([
        'name' => 'Review Insights Member',
        'email' => 'review-insights@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $member = Member::create([
        'user_id' => $memberUser->id,
        'member_number' => 'MEM-'.uniqid(),
        'name' => 'Review Insights Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(6),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);
    $member->fundAccount->update(['balance' => 25000]);

    app(LoanEligibilityOverrideRequestService::class)->submit($member, 'Please review my eligibility.');

    $snapshot = $this->service->forContext('eligibility_reviews');

    expect($snapshot)->toHaveKeys(['hero', 'kpis', 'pipeline', 'preview', 'top_blocked_rules'])
        ->and($snapshot['pipeline']['pending'])->toBe(1)
        ->and($snapshot['preview'])->toHaveCount(1)
        ->and($snapshot['hero']['tone'])->toBe('amber');
});

test('fund tiers snapshot reports utilization', function () {
    LoanTier::query()->delete();

    $tier = LoanTier::create([
        'tier_number' => 99,
        'label' => 'Standard',
        'min_amount' => 1000,
        'max_amount' => 50_000,
        'min_monthly_installment' => 500,
        'is_active' => true,
    ]);

    FundTier::create([
        'tier_number' => 99,
        'label' => 'Pool A',
        'loan_tier_id' => $tier->id,
        'percentage' => 25,
        'is_active' => true,
    ]);

    $snapshot = $this->service->fundTiersSnapshot();

    expect($snapshot)->toHaveKeys(['utilization', 'breakdown', 'kpis'])
        ->and($snapshot['breakdown'])->toHaveCount(1);
});

test('member portfolio snapshot links to my loans routes on member panel', function () {
    Filament::setCurrentPanel('member');

    $member = Member::create([
        'member_number' => 'MEM-'.uniqid(),
        'name' => 'Member Insights',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(6),
        'status' => 'active',
    ]);

    Loan::create([
        'member_id' => $member->id,
        'amount' => 3000,
        'amount_requested' => 3000,
        'interest_rate' => 10,
        'term_months' => 12,
        'status' => 'pending',
        'applied_at' => now(),
    ]);

    $snapshot = $this->service->memberPortfolioSnapshot($member->id);

    expect(Route::has('filament.member.resources.my-loans.index'))->toBeTrue()
        ->and($snapshot['kpis'])->not->toBeEmpty();

    foreach ($snapshot['kpis'] as $kpi) {
        expect($kpi['url'] ?? '')->toContain('/member/my-loans');
    }
});

test('loan detail snapshot on member panel uses my loan view urls', function () {
    Filament::setCurrentPanel('member');

    $member = Member::create([
        'member_number' => 'MEM-'.uniqid(),
        'name' => 'Detail Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(6),
        'status' => 'active',
    ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 4000,
        'amount_requested' => 4000,
        'amount_approved' => 4000,
        'interest_rate' => 10,
        'term_months' => 12,
        'status' => 'pending',
        'applied_at' => now(),
    ]);

    $snapshot = $this->service->loanDetailSnapshot($loan);

    expect($snapshot['view_url'])->toBe(MyLoanResource::getUrl('view', ['record' => $loan]))
        ->and($snapshot['snapshot']['queue_url'])->toContain('/member/my-loans');
});
