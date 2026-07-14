<?php

declare(strict_types=1);

use App\Filament\Member\Resources\MyLoans\Pages\ListMyLoans;
use App\Filament\Member\Resources\MyLoans\Pages\ViewMyLoan;
use App\Models\Central\Tenant;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\LoanService;
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

    if (!$tenant->domains()->where('domain', $this->domain)->exists()) {
        $tenant->domains()->create(['domain' => $this->domain]);
    }

    $this->memberUser = User::create([
        'name' => 'Settle Portal Member',
        'email' => 'settle-portal@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
        'preferred_locale' => 'en',
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-SETTLE01',
        'name' => 'Settle Portal Member',
        'email' => 'settle-portal@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $accounting = app(AccountingService::class);
    $accounting->createMemberAccounts($this->member);
    $this->member->cashAccount()->update(['balance' => 5000]);

    $this->loan = Loan::query()->create([
        'member_id' => $this->member->id,
        'amount' => 2000,
        'amount_requested' => 2000,
        'amount_approved' => 2000,
        'amount_disbursed' => 2000,
        'interest_rate' => 0,
        'term_months' => 2,
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

    LoanInstallment::query()->create([
        'loan_id' => $this->loan->id,
        'installment_number' => 2,
        'amount' => 1000,
        'due_date' => now()->addMonths(1),
        'status' => 'pending',
    ]);
});

test('member loans hub renders action modals without embedded table', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    Livewire::test(ListMyLoans::class)
        ->assertSeeHtml('wire:partial="action-modals"');
});

test('early settlement modal offers full payoff when cash covers balance', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    Livewire::test(ListMyLoans::class)
        ->mountAction('earlySettle')
        ->assertActionDataSet([
            'payment_mode' => 'full',
            'amount' => 2000.0,
        ]);
});

test('member loans hub early settlement action settles active loan', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    Livewire::test(ListMyLoans::class)
        ->assertSet('hubTab', 'active')
        ->call('openEarlySettlement', $this->loan->id)
        ->callMountedAction([
            'payment_mode' => 'full',
            'amount' => 2000,
            'option' => 'roll_up',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($this->loan->fresh()->status)->toBe('early_settled');

    $eligibility = app(LoanService::class)->checkEligibility($this->member->fresh());

    expect($eligibility['eligible'])->toBeFalse()
        ->and($eligibility['reasons'][0] ?? '')->toContain('settlement threshold waiting period');
});

test('member loan view openEarlySettlement mounts early settlement action', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    Livewire::test(ViewMyLoan::class, ['record' => $this->loan->getRouteKey()])
        ->call('openEarlySettlement')
        ->assertActionMounted('earlySettle');
});

test('member loan view early settlement action settles active loan', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    Livewire::test(ViewMyLoan::class, ['record' => $this->loan->getRouteKey()])
        ->assertActionVisible('earlySettle')
        ->callAction('earlySettle', [
            'payment_mode' => 'full',
            'amount' => 2000,
            'option' => 'roll_up',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($this->loan->fresh()->status)->toBe('early_settled');
});

test('early settlement form prefills affordable cash when full payoff exceeds balance', function () {
    $this->member->cashAccount()->update(['balance' => 100]);

    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    Livewire::test(ListMyLoans::class)
        ->mountAction('earlySettle')
        ->assertActionDataSet([
            'payment_mode' => 'partial',
            'amount' => 100.0,
            'option' => 'roll_up',
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($this->loan->fresh()->status)->toBe('active')
        ->and((float) $this->member->fresh()->getCashBalance())->toBe(0.0)
        ->and($this->loan->installments()->where('status', 'pending')->count())->toBe(2);
});

test('early settlement succeeds for partial amount within cash balance', function () {
    $this->member->cashAccount()->update(['balance' => 1000]);

    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    Livewire::test(ListMyLoans::class)
        ->call('openEarlySettlement', $this->loan->id)
        ->callMountedAction([
            'payment_mode' => 'partial',
            'amount' => 1000,
            'option' => 'roll_up',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($this->loan->fresh()->status)->toBe('active')
        ->and($this->loan->installments()->where('status', 'paid')->count())->toBe(1);
});

test('openEarlySettle query opens early settlement on active tab', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    Livewire::test(ListMyLoans::class, [
        'hubTab' => 'active',
        'openEarlySettleModal' => true,
    ])
        ->assertSet('hubTab', 'active')
        ->assertActionMounted('earlySettle');
});

test('openEarlySettlement method mounts early settlement action', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    Livewire::test(ListMyLoans::class)
        ->call('openEarlySettlement')
        ->assertActionMounted('earlySettle');
});

test('history tab hides repayment header actions', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    Livewire::test(ListMyLoans::class)
        ->call('setHubTab', 'history')
        ->assertActionHidden('earlySettle')
        ->assertActionHidden('payOpenPeriodRepayment')
        ->assertActionHidden('requestEligibilityOverride');
});

test('early settlement opens after switching back to active tab', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    Livewire::test(ListMyLoans::class)
        ->call('setHubTab', 'history')
        ->call('setHubTab', 'active')
        ->call('openEarlySettlement', $this->loan->id)
        ->assertActionMounted('earlySettle');
});

test('legacy settle hub url redirects to active tab', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    Livewire::test(ListMyLoans::class, ['hubTab' => 'settle'])
        ->assertSet('hubTab', 'active')
        ->assertActionMounted('earlySettle');
});
