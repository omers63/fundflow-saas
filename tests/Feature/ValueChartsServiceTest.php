<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\DelinquencyWorkspacePage;
use App\Filament\Tenant\Pages\LoanQueueWorkbenchPage;
use App\Filament\Tenant\Pages\ReconciliationOverviewPage;
use App\Filament\Tenant\Widgets\ContributionInsightsWidget;
use App\Filament\Tenant\Widgets\LoanInsightsWidget;
use App\Filament\Tenant\Widgets\MasterAccountsInsightsWidget;
use App\Filament\Tenant\Widgets\MemberInsightsWidget;
use App\Filament\Tenant\Widgets\TenantDashboardWidget;
use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\ValueChartsService;
use App\Support\BusinessDay;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    app()->setLocale('en');

    $admin = User::create([
        'name' => 'Value Charts Admin',
        'email' => 'value-charts-'.uniqid().'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
        'preferred_locale' => 'en',
    ]);

    $this->actingAs($admin, 'tenant');
});

it('builds liquidity stack from master balances', function () {
    Account::query()->delete();
    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 1000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 2000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 3000, 'is_master' => true]);

    $chart = app(ValueChartsService::class)->liquidityStack();

    expect($chart['type'])->toBe('stack')
        ->and($chart['total'])->toBe(6000.0)
        ->and(collect($chart['segments'])->pluck('value', 'key')->all())->toMatchArray([
            'cash' => 1000.0,
            'fund' => 2000.0,
            'bank' => 3000.0,
        ]);
});

it('builds pipeline funnel and recon mix payloads', function () {
    $service = app(ValueChartsService::class);

    $funnel = $service->pipelineFunnel();
    $recon = $service->reconExceptionMix();

    expect($funnel['type'])->toBe('funnel')
        ->and($funnel['steps'])->toHaveCount(4)
        ->and($recon)->toHaveKeys(['severity', 'domain'])
        ->and($recon['severity']['type'])->toBe('donut')
        ->and($recon['domain']['type'])->toBe('bars');
});

it('buckets delinquency aging by days past due', function () {
    $member = Member::create([
        'member_number' => 'MEM-VC-AGE',
        'name' => 'Aging Member',
        'monthly_contribution_amount' => 100,
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
        'applied_at' => now()->subMonths(4),
        'approved_at' => now()->subMonths(4),
        'disbursed_at' => now()->subMonths(4),
    ]);

    $today = BusinessDay::today();

    LoanInstallment::query()->create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'status' => 'overdue',
        'due_date' => $today->copy()->subDays(15),
        'overdue_since' => $today->copy()->subDays(15),
        'amount' => 500,
        'amount_collected' => 0,
    ]);

    LoanInstallment::query()->create([
        'loan_id' => $loan->id,
        'installment_number' => 2,
        'status' => 'overdue',
        'due_date' => $today->copy()->subDays(100),
        'overdue_since' => $today->copy()->subDays(100),
        'amount' => 800,
        'amount_collected' => 100,
    ]);

    $chart = app(ValueChartsService::class)->delinquencyAging();

    $byKey = collect($chart['buckets'])->keyBy('key');

    expect($chart['type'])->toBe('aging')
        ->and($byKey['1-30']['count'])->toBe(1)
        ->and($byKey['1-30']['amount'])->toBe(500.0)
        ->and($byKey['90+']['count'])->toBe(1)
        ->and($byKey['90+']['amount'])->toBe(700.0);
});

it('defers value chart work on dashboard and delinquency until folded open', function () {
    Livewire::test(TenantDashboardWidget::class)
        ->assertSuccessful()
        ->assertSee(__('Expand to load liquidity stack and treasury runway (cached separately).'))
        ->assertDontSee(__('Liquidity stack'))
        ->call('unfoldSection', 'value_charts')
        ->assertSee(__('Liquidity stack'))
        ->assertSee(__('Treasury runway'));

    Livewire::test(DelinquencyWorkspacePage::class, ['sideTab' => 'overview'])
        ->assertSuccessful()
        ->assertSee(__('Expand to load overdue installment age buckets (cached).'))
        ->assertDontSee(__('Open overdue / past-due installments by age'))
        ->call('unfoldSection', 'value_chart_aging')
        ->assertSee(__('Delinquency aging'));
});

it('defers value charts on queue and recon until folded open', function () {
    Livewire::test(LoanQueueWorkbenchPage::class)
        ->assertSuccessful()
        ->assertSee(__('Expand to load stage funnel (cached).'))
        ->assertDontSee(__('Loan pipeline funnel'))
        ->call('unfoldSection', 'value_chart_pipeline')
        ->assertSee(__('Loan pipeline funnel'));

    Livewire::test(ReconciliationOverviewPage::class, ['sideTab' => 'overview'])
        ->assertSuccessful()
        ->assertSee(__('Expand to load severity and domain breakdown (cached).'))
        ->call('unfoldSection', 'value_chart_recon')
        ->assertSee(__('Recon exceptions'));
});

it('exposes unfold actions on insight widgets', function () {
    foreach ([
        ContributionInsightsWidget::class,
        LoanInsightsWidget::class,
        MemberInsightsWidget::class,
        MasterAccountsInsightsWidget::class,
    ] as $widget) {
        Livewire::test($widget)
            ->assertSuccessful()
            ->call('unfoldSection', 'probe')
            ->assertSet('unfoldedSections.probe', true);
    }
});
