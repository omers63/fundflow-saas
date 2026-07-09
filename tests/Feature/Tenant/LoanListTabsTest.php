<?php

declare(strict_types=1);

use App\Filament\Support\LoanListTableHeaderActions;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\Loans\Pages\ListLoans;
use App\Models\Central\Tenant;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
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

    $admin = User::create([
        'name' => 'Loans Admin',
        'email' => 'loans-tabs@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($admin, 'tenant');
});

test('loans list defaults to collection tab with collect segment', function () {
    Livewire::test(ListLoans::class)
        ->assertSuccessful()
        ->assertSet('activeTab', 'collection')
        ->assertSet('collectionSegment', 'collect')
        ->assertSee(__('To collect'), false);

    expect(LoanResource::listUrl())
        ->toBe(LoanResource::listUrl('collection'))
        ->not->toContain('tab=');

    expect(LoanResource::listTabUrl('emi_collect'))
        ->not->toContain('tab=emi_collect');
});

test('legacy emi collect tab url redirects to collection segment', function () {
    $path = parse_url(LoanResource::listTabUrl('emi_collect'), PHP_URL_PATH) ?? '/admin/loans';
    $query = parse_url(LoanResource::listTabUrl('emi_collect'), PHP_URL_QUERY);

    $this->get('http://'.$this->domain.$path.($query ? '?'.$query : ''))
        ->assertSuccessful()
        ->assertSee(__('Collection'), false)
        ->assertSee(__('To collect'), false);
});

test('legacy overdue installments tab url maps to delinquency view', function () {
    expect(LoanResource::listTabUrl('overdue_installments'))
        ->toContain('tab=delinquency')
        ->not->toContain('tab=overdue_installments');

    $path = parse_url(LoanResource::listTabUrl('overdue_installments'), PHP_URL_PATH) ?? '/admin/loans';
    $query = parse_url(LoanResource::listTabUrl('overdue_installments'), PHP_URL_QUERY);

    $this->get('http://'.$this->domain.$path.($query ? '?'.$query : ''))
        ->assertSuccessful()
        ->assertSee(__('Delinquency'), false)
        ->assertSee(__('Overdue installments'), false);
});

test('legacy guarantor exposure tab url maps to delinquency guarantor view', function () {
    expect(LoanResource::listTabUrl('guarantor_exposure'))
        ->toContain('tab=delinquency')
        ->toContain('view=guarantor');
});

test('legacy eligibility reviews tab url maps to portfolio eligibility view', function () {
    expect(LoanResource::listTabUrl('eligibility_reviews'))
        ->toContain('tab=portfolio')
        ->toContain('portfolioView=eligibility');
});

test('collected segment url omits default tab parameter', function () {
    expect(LoanResource::listTabUrl('emi_collected'))
        ->toContain('segment=collected')
        ->not->toContain('tab=collection');
});

test('portfolio tab exposes import and export header actions', function () {
    $component = Livewire::test(ListLoans::class)
        ->set('activeTab', 'portfolio');

    $names = LoanListTableHeaderActions::flattenActionNames(
        $component->instance()->getTable()->getHeaderActions(),
    );

    expect($names)->toContain('importLoans', 'exportLoans', 'importRepayments', 'exportRepayments');
});

test('collection tab exposes cycle selector and drives selected period', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $cycles = app(ContributionCycleService::class);
    [$openMonth, $openYear] = $cycles->currentOpenPeriod();
    $previous = Carbon::create($openYear, $openMonth, 1)->subMonthNoOverflow();
    $previousKey = $cycles->contributionCycleKey((int) $previous->month, (int) $previous->year);

    Livewire::test(ListLoans::class)
        ->assertSet('activeTab', 'collection')
        ->assertSee(__('Collection cycle'), false)
        ->set('selectedCycle', $previousKey)
        ->assertSet('selectedCycle', $previousKey)
        ->assertSee($cycles->periodLabel((int) $previous->month, (int) $previous->year), false);

    Carbon::setTestNow();
});

test('collection tab table exposes cycle collection header group', function () {
    $component = Livewire::test(ListLoans::class)
        ->set('activeTab', 'collection')
        ->set('collectionSegment', 'collect');

    expect($component->instance()->getTable()->getHeaderActions())->toHaveCount(1)
        ->and($component->instance()->getTable()->getHeaderActions()[0]->getLabel())->toBe(__('Cycle collection'));
});

test('collected segment pill shows installment count badge', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $cycles = app(ContributionCycleService::class);
    [$month, $year] = $cycles->currentOpenPeriod();

    $accounting = app(AccountingService::class);
    $member = Member::create([
        'member_number' => 'LOAN-COL-BADGE',
        'name' => 'Collected Badge Borrower',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);

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
        'status' => 'paid',
        'paid_at' => now(),
    ]);

    expect(LoanResource::collectedEmiInstallmentCount())->toBe(1);

    Livewire::test(ListLoans::class)
        ->assertSuccessful()
        ->assertSee(__('Collected'), false)
        ->assertSee('>1</span>', false);

    Carbon::setTestNow();
});

test('delinquency tab exposes maintenance actions on overdue view', function () {
    Livewire::test(ListLoans::class)
        ->set('activeTab', 'delinquency')
        ->set('delinquencyView', 'overdue')
        ->mountTableAction('markOverdueInstallments')
        ->callMountedTableAction()
        ->assertNotified();
});
