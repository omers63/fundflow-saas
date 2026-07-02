<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\Dashboard;
use App\Filament\Tenant\Pages\JobsPage;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Widgets\TenantDashboardWidget;
use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\TenantDashboardService;
use App\Support\BusinessDay;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    $this->service = app(TenantDashboardService::class);

    Account::query()->delete();
    Member::query()->delete();
});

test('tenant dashboard kpi labels follow the active locale', function () {
    $user = User::create([
        'name' => 'Fund Admin',
        'email' => 'kpi-admin@fund.test',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    Member::create([
        'member_number' => 'MEM-KPI',
        'name' => 'KPI Member',
        'monthly_contribution_amount' => 100,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 1000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 5000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 2000, 'is_master' => true]);

    $this->actingAs($user, 'tenant');

    app()->setLocale('ar');

    $labels = collect($this->service->snapshot()['kpi_stats'])->pluck('label')->all();

    expect($labels)->toContain('تحصيل الدورة')
        ->and($labels)->toContain('أعضاء نشطون')
        ->and($labels)->toContain('استثناءات المطابقة');
});

test('tenant dashboard snapshot includes greeting and workspace links', function () {
    $user = User::create([
        'name' => 'Fund Admin',
        'email' => 'admin@fund.test',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $this->actingAs($user, 'tenant');

    Member::create([
        'member_number' => 'MEM-001',
        'name' => 'Test Member',
        'monthly_contribution_amount' => 100,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 1000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 5000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 2000, 'is_master' => true]);

    $snapshot = $this->service->snapshot();

    expect($snapshot['greeting']['name'])->toBe('Fund Admin')
        ->and($snapshot['greeting']['fund_name'])->toBeString()->not->toBeEmpty()
        ->and($snapshot['quick_actions'])->toHaveCount(6)
        ->and($snapshot['gauges'])->toHaveCount(4)
        ->and($snapshot['balances'])->toHaveCount(3)
        ->and($snapshot['workspace_sections'])->not->toBeEmpty()
        ->and($snapshot['contribution_trend'])->toHaveCount(6)
        ->and($snapshot['loan_trend'])->toHaveCount(6)
        ->and($snapshot['loan_trend'][0])->toHaveKeys(['label', 'total', 'active', 'pending', 'completed'])
        ->and($snapshot['loan_portfolio'])->toHaveKeys([
            'active_count',
            'active_amount_total',
            'outstanding_total',
            'overdue_installments',
            'queue_count',
            'loans_url',
            'active_loans_url',
            'outstanding_url',
            'overdue_url',
            'queue_url',
        ])
        ->and($snapshot['lifetime_fund_activity'])->toHaveKeys([
            'loan_count',
            'loan_amount_total',
            'contributions_total',
            'repayments_total',
            'collections_total',
            'loans_url',
            'contributions_url',
            'collections_url',
        ])
        ->and(
            collect($snapshot['workspace_sections'])
                ->flatMap(fn (array $s): array => $s['links'])
                ->pluck('url')
                ->every(fn ($url): bool => is_string($url) && $url !== '')
        )->toBeTrue();
});

test('tenant dashboard resolves filament page urls', function () {
    Filament::setCurrentPanel('tenant');

    expect(Dashboard::getUrl())->toBeString()->not->toBeEmpty()
        ->and(ContributionResource::listTabUrl('collect'))->toBeString()->not->toBeEmpty()
        ->and(JobsPage::getUrl())->toContain('jobs')
        ->and(LoanResource::listTabUrl('overdue_installments'))->toBeString()->not->toBeEmpty()
        ->and(MemberResource::getUrl('index'))->toBeString()->not->toBeEmpty();
});

test('jobs page registers in tenant panel navigation', function () {
    $admin = User::create([
        'name' => 'Jobs Admin',
        'email' => 'jobs-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Filament::setCurrentPanel('tenant');
    $this->actingAs($admin, 'tenant');

    expect(JobsPage::canAccess())->toBeTrue()
        ->and(JobsPage::shouldRegisterNavigation())->toBeFalse()
        ->and(JobsPage::getUrl())->toContain('/admin/jobs');
});

test('tenant dashboard pool health includes thirty day sparkline', function () {
    $user = User::create([
        'name' => 'Pool Admin',
        'email' => 'pool-admin@fund.test',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $this->actingAs($user, 'tenant');

    $cash = Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 1000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 5000, 'is_master' => true]);

    Transaction::create([
        'account_id' => $cash->id,
        'type' => 'credit',
        'amount' => 200,
        'balance_after' => 1200,
        'description' => 'Pool inflow',
        'transacted_at' => BusinessDay::now()->subDay(),
    ]);

    $pool = $this->service->snapshot()['pool_health'];

    expect($pool['sparkline'])->toHaveCount(30)
        ->and($pool['sparkline_max'])->toBeGreaterThan(0)
        ->and($pool['sparkline_end'])->toBe(6000.0)
        ->and($pool['sparkline_start'])->toBe(5800.0);
});

test('tenant dashboard pool health renders readable variance status markup', function () {
    $user = User::create([
        'name' => 'Drift Admin',
        'email' => 'drift-admin@fund.test',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 1500, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 5000, 'is_master' => true]);

    $this->actingAs($user, 'tenant');

    $html = Livewire::test(TenantDashboardWidget::class)
        ->assertSuccessful()
        ->html();

    expect($html)
        ->toContain('ff-tenant-pool-health')
        ->toContain('ff-tenant-pool-health__status')
        ->toContain(__('Variance detected'))
        ->toContain(__('Cash drift').':')
        ->toContain(__('Fund drift').':');
});

test('tenant dashboard loan portfolio summarizes active loan count value and outstanding', function () {
    $user = User::create([
        'name' => 'Loan Portfolio Admin',
        'email' => 'loan-portfolio-admin@fund.test',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $member = Member::create([
        'member_number' => 'MEM-LOAN-PORT',
        'name' => 'Borrower',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $loan = Loan::query()->create([
        'member_id' => $member->id,
        'amount' => 15_000,
        'amount_requested' => 15_000,
        'amount_approved' => 15_000,
        'amount_disbursed' => 15_000,
        'interest_rate' => 0,
        'term_months' => 10,
        'monthly_repayment' => 1500,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => now()->subMonths(2),
        'approved_at' => now()->subMonths(2),
        'disbursed_at' => now()->subMonths(2),
    ]);

    LoanInstallment::query()->create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 2500,
        'due_date' => now()->subDays(3),
        'status' => 'overdue',
    ]);

    LoanInstallment::query()->create([
        'loan_id' => $loan->id,
        'installment_number' => 2,
        'amount' => 1500,
        'due_date' => now()->addDays(20),
        'status' => 'pending',
    ]);

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 1000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 5000, 'is_master' => true]);

    $this->actingAs($user, 'tenant');

    $portfolio = $this->service->snapshot()['loan_portfolio'];

    expect($portfolio['active_count'])->toBe(1)
        ->and($portfolio['active_amount_total'])->toBe(15_000.0)
        ->and($portfolio['outstanding_total'])->toBe(4_000.0)
        ->and($portfolio['overdue_installments'])->toBe(1);

    $html = Livewire::test(TenantDashboardWidget::class)
        ->assertSuccessful()
        ->html();

    expect($html)
        ->toContain('ff-tenant-loan-portfolio')
        ->toContain(__('Active loan portfolio'))
        ->toContain(__('Portfolio value'))
        ->toContain(__('Outstanding'));
});

test('tenant dashboard lifetime fund activity summarizes loans contributions and collections', function () {
    $user = User::create([
        'name' => 'Lifetime Admin',
        'email' => 'lifetime-admin@fund.test',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $member = Member::create([
        'member_number' => 'MEM-LIFE',
        'name' => 'Lifetime Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $loan = Loan::query()->create([
        'member_id' => $member->id,
        'amount' => 10_000,
        'amount_requested' => 10_000,
        'amount_approved' => 10_000,
        'amount_disbursed' => 10_000,
        'interest_rate' => 0,
        'term_months' => 10,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => now()->subMonths(3),
        'approved_at' => now()->subMonths(3),
        'disbursed_at' => now()->subMonths(3),
    ]);

    Loan::query()->create([
        'member_id' => $member->id,
        'amount' => 5_000,
        'amount_requested' => 5_000,
        'amount_approved' => 5_000,
        'amount_disbursed' => 0,
        'interest_rate' => 0,
        'term_months' => 6,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'pending',
        'applied_at' => now(),
    ]);

    Contribution::factory()->posted()->create([
        'member_id' => $member->id,
        'amount' => 3_000,
    ]);

    Contribution::factory()->create([
        'member_id' => $member->id,
        'amount' => 999,
        'status' => 'pending',
    ]);

    LoanRepayment::factory()->create([
        'loan_id' => $loan->id,
        'amount' => 1_500,
    ]);

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 1000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 5000, 'is_master' => true]);

    $this->actingAs($user, 'tenant');

    $lifetime = $this->service->snapshot()['lifetime_fund_activity'];

    expect($lifetime['loan_count'])->toBe(1)
        ->and($lifetime['loan_amount_total'])->toBe(10_000.0)
        ->and($lifetime['contributions_total'])->toBe(3_000.0)
        ->and($lifetime['repayments_total'])->toBe(1_500.0)
        ->and($lifetime['collections_total'])->toBe(4_500.0)
        ->and($lifetime['loans_url'])->toBe(LoanResource::getUrl('index'))
        ->and($lifetime['contributions_url'])->toBe(ContributionResource::getUrl('index'));

    $html = Livewire::test(TenantDashboardWidget::class)
        ->assertSuccessful()
        ->html();

    expect($html)
        ->toContain('ff-tenant-lifetime-activity')
        ->toContain(__('Lifetime fund activity'))
        ->toContain(__('Total collections'))
        ->toContain(__('Contributions + repayments'));
});
