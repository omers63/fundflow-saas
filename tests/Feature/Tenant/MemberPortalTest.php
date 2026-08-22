<?php

use App\Filament\Member\Pages\ApplyForLoan;
use App\Filament\Member\Pages\LoanCalculatorPage;
use App\Filament\Member\Pages\MemberDashboard;
use App\Filament\Member\Resources\MyAccounts\MyAccountResource;
use App\Filament\Member\Resources\MyContributions\MyContributionResource;
use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Filament\Member\Resources\MyMessages\MyMessageResource;
use App\Filament\Support\DatabaseNotificationsRefresh;
use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\DirectMessage;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Support\BusinessDaySettings;
use App\Support\LoanCalculatorFundingApproach;
use App\Support\LoanFundingStrategy;
use App\Support\LoanSettings;
use App\Support\PublicPageSettings;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Contribution::query()->delete();
    Loan::query()->delete();
    Member::query()->delete();
    User::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);

    $accounting = app(AccountingService::class);

    $this->adminUser = User::create([
        'name' => 'Fund Admin',
        'email' => 'admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->memberUserA = User::create([
        'name' => 'Alice Member',
        'email' => 'alice@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->memberA = Member::create([
        'user_id' => $this->memberUserA->id,
        'member_number' => 'MEM-A001',
        'name' => 'Alice Member',
        'email' => 'alice@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subMonths(14),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($this->memberA);

    $this->memberUserB = User::create([
        'name' => 'Bob Member',
        'email' => 'bob@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->memberB = Member::create([
        'user_id' => $this->memberUserB->id,
        'member_number' => 'MEM-B001',
        'name' => 'Bob Member',
        'email' => 'bob@fund.test',
        'monthly_contribution_amount' => 2000,
        'joined_at' => now()->subMonths(6),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($this->memberB);

    Contribution::create(['member_id' => $this->memberA->id, 'period' => now()->subMonth()->startOfMonth(), 'amount' => 1000, 'status' => 'posted', 'posted_at' => now()]);
    Contribution::create(['member_id' => $this->memberA->id, 'period' => now()->subMonths(2)->startOfMonth(), 'amount' => 1000, 'status' => 'posted', 'posted_at' => now()]);
    Contribution::create(['member_id' => $this->memberB->id, 'period' => now()->subMonth()->startOfMonth(), 'amount' => 2000, 'status' => 'posted', 'posted_at' => now()]);

    Loan::create([
        'member_id' => $this->memberA->id,
        'amount' => 5000,
        'interest_rate' => 5,
        'term_months' => 12,
        'monthly_repayment' => 437.50,
        'total_repaid' => 0,
        'status' => 'disbursed',
        'applied_at' => now()->subWeek(),
        'approved_at' => now()->subDays(5),
        'disbursed_at' => now()->subDays(3),
    ]);
});

test('admin can access admin panel', function () {
    $panel = filament()->getPanel('tenant');
    expect($this->adminUser->canAccessPanel($panel))->toBeTrue();
});

test('member cannot access admin panel', function () {
    $panel = filament()->getPanel('tenant');
    expect($this->memberUserA->canAccessPanel($panel))->toBeFalse();
});

test('member can access member portal', function () {
    $panel = filament()->getPanel('member');
    expect($this->memberUserA->canAccessPanel($panel))->toBeTrue();
});

test('user without member profile cannot access member portal', function () {
    $orphanUser = User::create([
        'name' => 'Orphan',
        'email' => 'orphan@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $panel = filament()->getPanel('member');
    expect($orphanUser->canAccessPanel($panel))->toBeFalse();
});

test('account resource scopes to authenticated member', function () {
    auth('tenant')->login($this->memberUserA);

    $query = MyAccountResource::getEloquentQuery();
    $accounts = $query->get();

    expect($accounts)->toHaveCount(2);
    expect($accounts->pluck('member_id')->unique()->values()->all())->toBe([$this->memberA->id]);
    expect($accounts->where('is_master', true))->toHaveCount(0);
});

test('contribution resource scopes to authenticated member', function () {
    auth('tenant')->login($this->memberUserA);

    $query = MyContributionResource::getEloquentQuery();
    $contributions = $query->get();

    expect($contributions)->toHaveCount(2);
    expect($contributions->pluck('member_id')->unique()->values()->all())->toBe([$this->memberA->id]);
});

test('loan resource scopes to authenticated member', function () {
    auth('tenant')->login($this->memberUserA);

    $query = MyLoanResource::getEloquentQuery();
    $loans = $query->get();

    expect($loans)->toHaveCount(1);
    expect($loans->first()->member_id)->toBe($this->memberA->id);
});

test('member B sees no loans because they have none', function () {
    auth('tenant')->login($this->memberUserB);

    $query = MyLoanResource::getEloquentQuery();
    expect($query->count())->toBe(0);
});

test('member B sees only their own contributions', function () {
    auth('tenant')->login($this->memberUserB);

    $query = MyContributionResource::getEloquentQuery();
    $contributions = $query->get();

    expect($contributions)->toHaveCount(1);
    expect($contributions->first()->amount)->toBe('2000.00');
});

test('portal resources cannot be created', function () {
    expect(MyAccountResource::canCreate())->toBeFalse();
    expect(MyContributionResource::canCreate())->toBeFalse();
    expect(MyLoanResource::canCreate())->toBeFalse();
});

test('user model has member relationship', function () {
    expect($this->memberUserA->member)->not->toBeNull();
    expect($this->memberUserA->member->id)->toBe($this->memberA->id);
    expect($this->adminUser->member)->toBeNull();
});

test('member panel uses custom dashboard page', function () {
    $panel = filament()->getPanel('member');

    expect($panel->getPages())->toContain(MemberDashboard::class);
});

test('message resource scopes to member admin conversations', function () {
    auth('tenant')->login($this->memberUserA);

    DirectMessage::create([
        'from_user_id' => $this->adminUser->id,
        'to_user_id' => $this->memberUserA->id,
        'subject' => 'Notice',
        'body' => 'Please review your statement.',
    ]);

    DirectMessage::create([
        'from_user_id' => $this->memberUserB->id,
        'to_user_id' => $this->adminUser->id,
        'subject' => 'Other member',
        'body' => 'Should not appear',
    ]);

    expect(MyMessageResource::getEloquentQuery()->count())->toBe(1);
});

test('apply for loan page is registered on member panel', function () {
    expect(ApplyForLoan::getSlug())->toBe('apply-for-loan');
});

test('loan calculator page renders for member', function () {
    $this->actingAs($this->memberUserA, 'tenant');
    Filament::setCurrentPanel('member');

    $component = Livewire::test(LoanCalculatorPage::class)
        ->assertSuccessful()
        ->assertSee(__('Loan calculator'))
        ->assertSee(__('How this estimate works'))
        ->assertSee(__('Loan amount'))
        ->assertSeeHtml('ff-member-panel--collapsible')
        ->assertSeeHtml('<details')
        ->assertSee(__('Fund balance'))
        ->assertSee(__('Loan eligibility'))
        ->assertSee(__('Settlement threshold'))
        ->assertSee(__('Eligibility threshold'))
        ->assertSee(__('Calculate'), false)
        ->assertSee(__('Grace cycles before first repayment'), false)
        ->assertSee(__('Projected start date'), false)
        ->assertSee(__('Projected monthly contribution'), false)
        ->assertSeeHtml('wire:model.live="startDate"')
        ->assertSeeHtml('wire:model.live="projectedContributionAmount"')
        ->assertSet('projectedContributionAmount', 1000)
        ->set('projectedContributionAmount', 1500)
        ->assertSet('projectedContributionAmount', 1500)
        ->assertSeeHtml('wire:model="loanAmount"')
        ->assertDontSeeHtml('wire:model.live.debounce.400ms="loanAmount"')
        ->set('loanAmount', 10000)
        ->call('calculate')
        ->assertSet('loanAmount', 10000);
});

test('loan calculator shows fund balance and eligibility without raw html', function () {
    $this->actingAs($this->memberUserA, 'tenant');
    Filament::setCurrentPanel('member');

    Livewire::test(LoanCalculatorPage::class)
        ->assertSuccessful()
        ->assertSee(__('Fund balance'), false)
        ->assertSee(__('Not eligible'), false)
        ->assertDontSee('&lt;span class=&quot;ff-member-amount', false);
});

test('loan calculator tier chips expose lower and upper amounts', function () {
    LoanTier::query()->forceDelete();
    LoanTier::create([
        'tier_number' => 1,
        'label' => 'Standard',
        'min_amount' => 1000,
        'max_amount' => 50000,
        'min_monthly_installment' => 500,
        'is_active' => true,
    ]);

    $this->actingAs($this->memberUserA, 'tenant');
    Filament::setCurrentPanel('member');

    Livewire::test(LoanCalculatorPage::class)
        ->assertSee(__('Loan tiers'))
        ->assertSeeHtml('ff-member-loan-calc-tiers-wrap')
        ->assertSeeHtml('ff-member-loan-calc-tiers')
        ->assertSeeHtml('ff-member-loan-calc-tier')
        ->assertSeeHtml('wire:click="$set(\'loanAmount\', 1000)"')
        ->assertSeeHtml('wire:click="$set(\'loanAmount\', 50000)"')
        ->set('loanAmount', 50000)
        ->assertSet('loanAmount', 50000)
        ->set('loanAmount', 1000)
        ->assertSet('loanAmount', 1000);
});

test('loan calculator shows repayment estimate when tier matches', function () {
    Setting::set('contribution', 'cycle_start_day', '6');
    BusinessDaySettings::saveFromForm('2025-01-15');
    LoanSettings::save([
        'settlement_threshold_pct' => 0.20,
        'eligibility_threshold_pct' => 0.10,
        'max_allowed_grace_cycles' => 2,
    ]);
    LoanTier::query()->forceDelete();
    LoanTier::create([
        'tier_number' => 1,
        'label' => 'Standard',
        'min_amount' => 1000,
        'max_amount' => 50000,
        'min_monthly_installment' => 500,
        'is_active' => true,
    ]);
    $this->memberA->fundAccount->update(['balance' => 10_000]);

    $this->actingAs($this->memberUserA, 'tenant');
    Filament::setCurrentPanel('member');

    $component = Livewire::test(LoanCalculatorPage::class)
        ->set('graceCycles', 1)
        ->set('fundingApproach', LoanCalculatorFundingApproach::MEMBER_FUND_TOPUP)
        ->set('loanAmount', 10000)
        ->call('calculate')
        ->assertSee(__('months'), false)
        ->assertSee('Standard', false)
        ->assertSee(__('Settlement amount'), false)
        ->assertSee(__('Eligibility threshold amount'), false)
        ->assertSee(__('Total to repay'), false)
        ->assertSee(__('Duration'), false)
        ->assertSee(__('This loan'), false)
        ->assertSee(__('After this loan'), false)
        ->assertSee(__('Your fund now'), false)
        ->assertSee(__('Projected fund at start'), false)
        ->assertSee(__('Projected start date'), false)
        ->assertSee(__('Estimated schedule'), false)
        ->assertSeeHtml('ff-member-loan-calc-schedule')
        ->assertSee(__('Grace'), false)
        ->assertSee(__('This cycle’s contribution is skipped because this cycle is grace.'), false);

    $calc = $component->instance()->calculations[0];

    expect($calc['settlement_amt'])->toBe(2000.0)
        ->and($calc['eligibility_amt'])->toBe(5000.0)
        ->and($calc['eligibility_base'])->toBe(50000.0)
        ->and($calc['installments'])->toBe((int) ceil($calc['total_repay'] / 500))
        ->and($calc['schedule']['first_due_date'])->toBe('2025-03-05')
        ->and($calc['schedule']['grace_cycles'])->toBe(1)
        ->and($calc['schedule']['current_cycle_contribution'])->toBe('exempt_grace');

    BusinessDaySettings::saveFromForm(null);
});

test('loan calculator shows eligible status when fund balance meets the minimum', function () {
    $this->memberA->fundAccount->update(['balance' => 10_000]);

    $this->actingAs($this->memberUserA, 'tenant');
    Filament::setCurrentPanel('member');

    Livewire::test(LoanCalculatorPage::class)
        ->assertSuccessful()
        ->assertSee(__('Eligible to apply'), false)
        ->assertDontSee(__('Not eligible'), false);
});

test('loan calculator funding strategy options are translated in Arabic locale', function () {
    app()->setLocale('ar');

    $options = LoanFundingStrategy::options();

    expect($options[LoanFundingStrategy::MEMBER_FUND_TOPUP])
        ->toBe('استخدام رصيد صندوقي (تُكمل الإدارة الباقي)')
        ->and($options[LoanFundingStrategy::SPLIT_PERCENTAGE])
        ->toContain('استخدام تقسيم الصندوق المُعرَّف')
        ->and($options[LoanFundingStrategy::SPLIT_WITH_EARLY_SETTLEMENT])
        ->toContain('تقسيم الصندوق مع تسوية مبكرة');
});

test('loan calculator exposes a combined funding approach list', function () {
    LoanSettings::save([
        'allow_funding_strategy_member_topup' => true,
        'allow_funding_strategy_split_percentage' => true,
        'allow_funding_strategy_split_with_early_settlement' => true,
        'allow_excess_fund_cash_out' => true,
    ]);

    $this->actingAs($this->memberUserA, 'tenant');
    Filament::setCurrentPanel('member');

    Livewire::test(LoanCalculatorPage::class)
        ->assertSuccessful()
        ->assertSee(__('Funding approach'), false)
        ->assertSee(__('Keep remaining balance in my fund account'), false)
        ->assertSee(__('Transfer excess to my cash account at disbursement'), false)
        ->assertSee(__('Apply remaining fund as early settlement (roll up schedule)'), false)
        ->assertSee(__('Apply remaining fund as early settlement (skip installments)'), false)
        ->set('fundingApproach', LoanCalculatorFundingApproach::SKIP_FUTURE)
        ->assertSet('fundingApproach', LoanCalculatorFundingApproach::SKIP_FUTURE);
});

test('loan calculator skip approach shows regular payments from excess on the schedule', function () {
    Setting::set('contribution', 'cycle_start_day', '6');
    BusinessDaySettings::saveFromForm('2025-01-15');
    LoanSettings::save([
        'settlement_threshold_pct' => 0.20,
        'eligibility_threshold_pct' => 0.10,
        'member_funding_split_pct' => 50,
        'allow_funding_strategy_split_with_early_settlement' => true,
        'max_allowed_grace_cycles' => 2,
    ]);
    LoanTier::query()->forceDelete();
    LoanTier::create([
        'tier_number' => 1,
        'label' => 'Standard',
        'min_amount' => 1000,
        'max_amount' => 50000,
        'min_monthly_installment' => 500,
        'is_active' => true,
    ]);
    $this->memberA->fundAccount->update(['balance' => 6500]);

    $this->actingAs($this->memberUserA, 'tenant');
    Filament::setCurrentPanel('member');

    $component = Livewire::test(LoanCalculatorPage::class)
        ->set('graceCycles', 1)
        ->set('fundingApproach', LoanCalculatorFundingApproach::SKIP_FUTURE)
        ->set('loanAmount', 10000)
        ->call('calculate')
        ->assertSee(__('Regular payment'), false)
        ->assertSeeHtml('ff-member-loan-calc-schedule-header')
        ->assertSeeHtml('ff-member-loan-calc-schedule-row');

    $paid = array_values(array_filter(
        $component->instance()->calculations[0]['schedule']['rows'],
        fn (array $row): bool => ($row['kind'] ?? '') === 'paid',
    ));

    expect($paid)->not->toBeEmpty()
        ->and(min(array_column($paid, 'amount')))->toBeGreaterThan(0);

    BusinessDaySettings::saveFromForm(null);
});

test('loan calculator lifecycle simulator supports regular payments and full early settlement', function () {
    Setting::set('contribution', 'cycle_start_day', '6');
    BusinessDaySettings::saveFromForm('2025-01-15');
    LoanSettings::save([
        'settlement_threshold_pct' => 0.10,
        'eligibility_threshold_pct' => 0.20,
        'member_funding_split_pct' => 50,
        'allow_funding_strategy_split_percentage' => true,
        'max_allowed_grace_cycles' => 2,
    ]);
    LoanTier::query()->forceDelete();
    LoanTier::create([
        'tier_number' => 4,
        'label' => 'Tier 4',
        'min_amount' => 91000,
        'max_amount' => 120000,
        'min_monthly_installment' => 2500,
        'is_active' => true,
    ]);
    $this->memberA->fundAccount->update(['balance' => 50_000]);

    $this->actingAs($this->memberUserA, 'tenant');
    Filament::setCurrentPanel('member');

    $component = Livewire::test(LoanCalculatorPage::class)
        ->set('graceCycles', 0)
        ->set('fundingApproach', LoanCalculatorFundingApproach::KEEP_IN_FUND)
        ->set('loanAmount', 100000)
        ->call('calculate')
        ->assertSee(__('Estimate'), false)
        ->assertSee(__('Lifecycle simulator'), false);

    expect($component->instance()->calculations)->not->toBeEmpty();

    $component
        ->call('setCalculatorMode', 'simulate')
        ->assertSet('calculatorMode', 'simulate');

    expect($component->instance()->simulation)->toBeArray()
        ->and($component->instance()->simulation['maturity_amount'])->toBe(60000.0)
        ->and($component->instance()->simulation['remaining_months'])->toBe(24)
        ->and($component->instance()->simulation['fund_balance'])->toBe(-50000.0);

    $component
        ->assertSeeHtml('ff-member-loan-sim')
        ->assertSeeHtml('ff-member-panel--collapsible')
        ->assertSee(__('Apply payments'), false)
        ->assertSee(__('Partial settlement amount'), false)
        ->assertSee(__('Amount needed for full maturity: :amount', ['amount' => '']), false)
        ->assertSee(__('Full settlement amount'), false)
        ->assertSee(__('Updating schedule'), false)
        ->assertSeeHtml('ff-member-loan-sim-stat-card')
        ->assertSeeHtml('ff-member-loan-sim-stat-card--cycles')
        ->assertSeeHtml('ff-member-loan-sim-stat-card--pending')
        ->assertSeeHtml('ff-member-loan-sim-stat-card--maturity')
        ->assertSee(__('Projected loan maturity date'), false)
        ->assertSee(__('total cycle(s)'), false)
        ->assertSee(__('pending installment(s)'), false)
        ->assertSee(__('Reset simulation'), false)
        ->assertDontSeeHtml('text-2xl font-bold text-primary-600')
        ->assertSee(__('Simulation history'), false)
        ->assertSeeHtml('ff-member-loan-sim-history')
        ->assertSeeHtml('data-label')
        ->assertSeeHtml('ff-member-loan-sim-pay-actions')
        ->assertSeeHtml('ff-member-amount--success')
        ->assertSeeHtml('ff-member-amount--danger');

    preg_match_all('/ff-member-loan-sim-history__owed[\s\S]*?<\/td>/', $component->html(), $owedCells);

    expect($owedCells[0] ?? [])->not->toBeEmpty();
    foreach ($owedCells[0] as $cell) {
        expect($cell)->toContain('ff-member-amount--danger');
    }

    expect($component->instance()->simulation['schedule_rows'])->toHaveCount(25)
        ->and($component->instance()->simulation['schedule_count'])->toBe(24)
        ->and($component->instance()->simulation['pending_count'])->toBe(24);

    $component
        ->set('simulationPaymentAmount', 60000)
        ->call('applySimulationPartialEarlySettlement')
        ->assertSee(__('Paid (normal maturity)'), false)
        ->assertSee(__('Partial settlement'), false)
        ->assertDontSee(__('Paid at normal maturity'), false)
        ->assertSee(__('After close'), false)
        ->assertSee(__('Date'), false)
        ->assertSeeHtml('id="loan-sim-after-close-date"');

    expect($component->instance()->simulation['status'])->toBe('paid')
        ->and($component->instance()->simulation['fund_balance'])->toBe(10000.0)
        ->and($component->instance()->simulationAfterCloseDate)->toBe($component->instance()->simulation['expected_maturity_date']);

    $component->call('startSimulationFromEstimate')
        ->call('applySimulationRegularPayment')
        ->call('applySimulationFullEarlySettlement')
        ->assertSee(__('Fully settled'), false);

    expect($component->instance()->simulation['status'])->toBe('fully_settled')
        ->and($component->instance()->simulation['fund_balance'])->toBe(50000.0)
        ->and($component->instance()->simulation['eligible_for_new_loan'])->toBeTrue();

    $component
        ->assertDontSee(__('Partial early settlement style'), false)
        ->assertSeeHtml('<table');

    BusinessDaySettings::saveFromForm(null);
});

test('loan calculator simulator partial early settlement rolls up even when estimate settlement option is skip', function () {
    Setting::set('contribution', 'cycle_start_day', '6');
    BusinessDaySettings::saveFromForm('2025-01-15');
    LoanSettings::save([
        'settlement_threshold_pct' => 0.10,
        'eligibility_threshold_pct' => 0.20,
        'member_funding_split_pct' => 50,
        'allow_funding_strategy_split_percentage' => true,
        'allow_excess_fund_cash_out' => true,
        'max_allowed_grace_cycles' => 2,
    ]);
    LoanTier::query()->forceDelete();
    LoanTier::create([
        'tier_number' => 4,
        'label' => 'Tier 4',
        'min_amount' => 91000,
        'max_amount' => 120000,
        'min_monthly_installment' => 2500,
        'is_active' => true,
    ]);
    $this->memberA->fundAccount->update(['balance' => 50_000]);

    $this->actingAs($this->memberUserA, 'tenant');
    Filament::setCurrentPanel('member');

    $component = Livewire::test(LoanCalculatorPage::class)
        ->set('graceCycles', 0)
        ->set('fundingApproach', LoanCalculatorFundingApproach::KEEP_IN_FUND)
        ->set('loanAmount', 100000)
        ->call('calculate')
        ->call('setCalculatorMode', 'simulate')
        ->set('simulationPaymentAmount', 10000)
        ->call('applySimulationPartialEarlySettlement');

    $rows = $component->instance()->simulation['schedule_rows'];
    $rolled = array_values(array_filter($rows, fn (array $row): bool => ($row['kind'] ?? '') === 'rolled_up'));
    $skipped = array_values(array_filter($rows, fn (array $row): bool => ($row['kind'] ?? '') === 'skipped'));

    expect($rolled)->toHaveCount(1)
        ->and($rolled[0]['amount'])->toBe(10000.0)
        ->and($skipped)->toBeEmpty()
        ->and($component->instance()->simulation['pending_count'])->toBe(20)
        ->and($component->instance()->simulation['schedule_count'])->toBe(21);

    BusinessDaySettings::saveFromForm(null);
});

test('loan calculator blocks estimate and simulate when member portion exceeds projected fund', function () {
    LoanSettings::save([
        'settlement_threshold_pct' => 0.10,
        'eligibility_threshold_pct' => 0.20,
        'member_funding_split_pct' => 50,
        'allow_funding_strategy_split_percentage' => true,
        'max_allowed_grace_cycles' => 2,
    ]);
    LoanTier::query()->forceDelete();
    LoanTier::create([
        'tier_number' => 4,
        'label' => 'Tier 4',
        'min_amount' => 91000,
        'max_amount' => 120000,
        'min_monthly_installment' => 2500,
        'is_active' => true,
    ]);
    $this->memberA->fundAccount->update(['balance' => 10_000]);

    $this->actingAs($this->memberUserA, 'tenant');
    Filament::setCurrentPanel('member');

    $component = Livewire::test(LoanCalculatorPage::class)
        ->set('graceCycles', 0)
        ->set('fundingApproach', LoanCalculatorFundingApproach::KEEP_IN_FUND)
        ->set('loanAmount', 100000)
        ->call('calculate')
        ->assertSee(__('Cannot estimate or simulate this loan'), false)
        ->assertSee(__('Your member portion (:portion) exceeds the projected fund at start (:fund).', [
            'portion' => number_format(50_000.0, 2),
            'fund' => number_format(10_000.0, 2),
        ]), false)
        ->call('setCalculatorMode', 'simulate')
        ->assertSet('calculatorMode', 'estimate');

    expect($component->instance()->calculations)->toBeEmpty()
        ->and($component->instance()->simulation)->toBeNull();
});

test('loan calculator projects fund after settling the active loan with regular payments', function () {
    Setting::set('contribution', 'cycle_start_day', '6');
    BusinessDaySettings::saveFromForm('2025-01-15');
    $this->memberA->fundAccount->update(['balance' => -6000]);
    $this->memberA->update(['monthly_contribution_amount' => 500]);

    $loan = Loan::create([
        'member_id' => $this->memberA->id,
        'amount' => 6000,
        'amount_requested' => 6000,
        'amount_approved' => 6000,
        'amount_disbursed' => 6000,
        'member_portion' => 6000,
        'master_portion' => 0,
        'interest_rate' => 0,
        'term_months' => 2,
        'monthly_repayment' => 3000,
        'status' => 'active',
        'applied_at' => now()->subMonths(2),
        'disbursed_at' => now()->subMonths(2),
    ]);

    foreach ([1, 2] as $number) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $number,
            'amount' => 3000,
            'due_date' => now()->addMonths($number),
            'status' => 'pending',
        ]);
    }

    $this->actingAs($this->memberUserA, 'tenant');
    Filament::setCurrentPanel('member');

    $component = Livewire::test(LoanCalculatorPage::class)
        ->set('startDate', '2025-10-15')
        ->set('projectedContributionAmount', 500)
        ->assertSee(__('Projected fund at start'), false)
        ->assertSeeHtml('ff-member-loan-calc-projected-fund__formula');

    $projection = $component->instance()->projection;
    $html = $component->html();
    preg_match(
        '/ff-member-loan-calc-projected-fund__formula[\s\S]*?<\/p>/',
        $html,
        $formula,
    );

    expect($projection['projected_fund'])->toBe(4000.0)
        ->and($projection['loan_repayment_cycles'])->toBe(2)
        ->and($projection['cycles_added'])->toBe(8)
        ->and($formula[0] ?? '')->toContain('ff-member-amount--danger');

    BusinessDaySettings::saveFromForm(null);
});

test('loan calculator shows a negative projected fund in red', function () {
    Setting::set('contribution', 'cycle_start_day', '6');
    BusinessDaySettings::saveFromForm('2025-01-15');
    $this->memberA->fundAccount->update(['balance' => -6000]);

    $this->actingAs($this->memberUserA, 'tenant');
    Filament::setCurrentPanel('member');

    $html = Livewire::test(LoanCalculatorPage::class)
        ->set('startDate', '2025-01-15')
        ->html();

    preg_match(
        '/ff-member-loan-calc-status[\s\S]*?ff-member-stat-card__hint/',
        $html,
        $fundCard,
    );
    preg_match(
        '/ff-member-loan-calc-projected-fund[\s\S]*?ff-member-loan-calc-projected-fund__formula/',
        $html,
        $projectedCard,
    );

    expect($fundCard[0] ?? '')->toContain('ff-member-amount--danger')
        ->and($projectedCard[0] ?? '')
        ->toContain('ff-member-amount--danger')
        ->not->toContain('text-emerald-900');

    BusinessDaySettings::saveFromForm(null);
});

test('member panel has database notifications enabled', function () {
    expect(filament()->getPanel('member')->hasDatabaseNotifications())->toBeTrue()
        ->and(filament()->getPanel('member')->hasLazyLoadedDatabaseNotifications())->toBeFalse()
        ->and(filament()->getPanel('member')->getDatabaseNotificationsPollingInterval())
        ->toBe(DatabaseNotificationsRefresh::panelPollingInterval())
        ->and(filament()->getPanel('member')->hasBroadcasting())->toBeTrue()
        ->and(config('filament.broadcasting.echo.broadcaster'))->toBe('reverb');
});

test('member portal topbar shows fund name beside logo', function () {
    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'fund_name_en' => 'Al Noor Fund',
        'fund_name_ar' => 'صندوق النور',
    ]);

    app()->setLocale('en');

    Filament::setCurrentPanel('member');

    $html = FilamentView::renderHook(PanelsRenderHook::TOPBAR_LOGO_AFTER)->toHtml();

    expect($html)
        ->toContain('ff-member-topbar-fund-name')
        ->toContain('Al Noor Fund');
});
