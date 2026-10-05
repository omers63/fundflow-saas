<?php

declare(strict_types=1);

use App\Filament\Member\Pages\CashAccountPage;
use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\GatewayPayment;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\Gateway\GatewayDueResolver;
use App\Services\Gateway\GatewayPaymentService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Config::set('gateway.driver', 'fake');

    Account::query()->delete();
    Member::query()->delete();
    User::query()->delete();
    GatewayPayment::query()->delete();
    Contribution::query()->delete();
    LoanInstallment::query()->delete();
    Loan::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);

    $this->memberUser = User::create([
        'name' => 'Pay Now Member',
        'email' => 'pay-now@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-PN1',
        'name' => 'Pay Now Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYears(2),
        'status' => 'active',
        'email' => 'pay-now@test.com',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);

    Filament::setCurrentPanel(Filament::getPanel('member'));
    $this->actingAs($this->memberUser, 'tenant');
});

test('pay now creates pending intent for exact EMI due', function () {
    $loan = Loan::create([
        'member_id' => $this->member->id,
        'amount' => 5000,
        'amount_approved' => 5000,
        'amount_disbursed' => 5000,
        'status' => 'active',
        'interest_rate' => 0,
        'term_months' => 10,
        'installments_count' => 10,
        'monthly_repayment' => 500,
        'disbursed_at' => now()->subMonth(),
        'applied_at' => now()->subMonths(2),
        'approved_at' => now()->subMonth(),
    ]);

    $installment = LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 500,
        'due_date' => now()->toDateString(),
        'status' => 'pending',
        'late_fee_amount' => 25,
        'amount_collected' => 0,
    ]);

    $due = app(GatewayDueResolver::class)->nextEmiDue($loan, $this->member);

    expect($due)->not->toBeNull()
        ->and($due['amount'])->toBe(525.0)
        ->and($due['purpose'])->toBe(GatewayPayment::PURPOSE_EMI);

    $payment = app(GatewayPaymentService::class)->createIntent(
        $this->member,
        $due['purpose'],
        $due['amount'],
        expectedOwed: $due['amount'],
        payable: $installment,
    );

    expect($payment->status)->toBe(GatewayPayment::STATUS_PENDING)
        ->and((float) $payment->amount)->toBe(525.0);
});

test('create intent rejects wrong EMI amount', function () {
    app(GatewayPaymentService::class)->createIntent(
        $this->member,
        GatewayPayment::PURPOSE_EMI,
        100.0,
        expectedOwed: 525.0,
    );
})->throws(InvalidArgumentException::class);

test('pay now action is hidden when contribution owed is zero', function () {
    Livewire::test(CashAccountPage::class)
        ->assertActionHidden('payContributionGateway');
});

test('pay now action visible when contribution is outstanding', function () {
    Contribution::create([
        'member_id' => $this->member->id,
        'period' => (int) now()->format('Ym'),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 0,
        'status' => 'pending',
    ]);

    Livewire::test(CashAccountPage::class)
        ->assertActionVisible('payContributionGateway');
});
