<?php

declare(strict_types=1);

use App\Filament\Member\Resources\MyLoans\Pages\ListMyLoans;
use App\Models\Central\Tenant;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\MemberLoansHubService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    app()->setLocale('en');
    Filament::setCurrentPanel('member');

    $tenant = Tenant::find('testing');
    $this->domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $this->domain)->exists()) {
        $tenant->domains()->create(['domain' => $this->domain]);
    }

    $this->memberUser = User::create([
        'name' => 'Loans Hub Member',
        'email' => 'loans-hub@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
        'preferred_locale' => 'en',
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-LOAN01',
        'name' => 'Loans Hub Member',
        'email' => 'loans-hub@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);

    $this->loan = Loan::query()->create([
        'member_id' => $this->member->id,
        'amount' => 10000,
        'amount_requested' => 10000,
        'amount_approved' => 10000,
        'amount_disbursed' => 10000,
        'interest_rate' => 0,
        'term_months' => 10,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => now()->subMonth(),
        'approved_at' => now()->subMonth(),
        'disbursed_at' => now()->subMonth(),
    ]);

    LoanInstallment::query()->create([
        'loan_id' => $this->loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => now()->addDays(10),
        'status' => 'pending',
    ]);
});

test('loans hub renders tab shell and active loan card', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    $this->get('http://'.$this->domain.'/member/my-loans')
        ->assertSuccessful()
        ->assertSee('ff-member-loans-hub', false)
        ->assertSee(__('Active'), false)
        ->assertSee(__('Repayment schedule'), false)
        ->assertSee(__('Loan #:id', ['id' => $this->loan->id]), false);
});

test('loans hub history tab shows completed loan cards', function () {
    $completed = Loan::query()->create([
        'member_id' => $this->member->id,
        'amount' => 5000,
        'amount_requested' => 5000,
        'amount_approved' => 5000,
        'amount_disbursed' => 5000,
        'interest_rate' => 0,
        'term_months' => 5,
        'monthly_repayment' => 1000,
        'total_repaid' => 5000,
        'status' => 'completed',
        'applied_at' => now()->subYears(2),
        'approved_at' => now()->subYears(2),
        'disbursed_at' => now()->subYears(2),
        'settled_at' => now()->subYear(),
    ]);

    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    Livewire::test(ListMyLoans::class)
        ->call('setHubTab', 'history')
        ->assertSet('hubTab', 'history')
        ->assertSee(__('History'), false)
        ->assertSee(__('Loan #:id', ['id' => $completed->id]), false)
        ->assertActionHidden('earlySettle');
});

test('active tab exposes early settlement on loan card not header', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    $component = Livewire::test(ListMyLoans::class)
        ->assertSet('hubTab', 'active')
        ->assertSee(__('Early settlement'), false);

    $headerNames = collect($component->instance()->getCachedHeaderActions())
        ->map(fn ($action) => $action->getName())
        ->all();

    expect($headerNames)->not->toContain('earlySettle');
});

test('pending loan card shows a projected funding estimate', function () {
    $pending = Loan::query()->create([
        'member_id' => $this->member->id,
        'amount' => 5000,
        'amount_requested' => 5000,
        'interest_rate' => 0,
        'term_months' => 5,
        'monthly_repayment' => 0,
        'total_repaid' => 0,
        'status' => 'pending',
        'applied_at' => now()->subDay(),
        'loan_tier_id' => LoanTier::forAmount(5000)?->id,
    ]);

    $card = app(MemberLoansHubService::class)->loanCard($pending);
    $activeCard = app(MemberLoansHubService::class)->loanCard($this->loan);

    expect($card['projected_funding_label'])->not->toBeNull()
        ->and($activeCard['projected_funding_label'])->toBeNull();

    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    Livewire::test(ListMyLoans::class)
        ->assertSet('hubTab', 'active')
        ->assertSee(__('Estimated funding'), false)
        ->assertSee($card['projected_funding_label'], false);
});

test('legacy settle hub url opens repayment on active tab', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    $this->get('http://'.$this->domain.'/member/my-loans?hub=settle')
        ->assertSuccessful()
        ->assertSee(__('Loan #:id', ['id' => $this->loan->id]), false)
        ->assertSee(__('Use Pay this period above or Early settlement on your loan card to repay from your cash balance.'), false);
});
