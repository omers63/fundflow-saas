<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\AuditSystemPage;
use App\Models\Tenant\Account;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\OutboundPayment;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\MasterExpenseDisbursementService;
use App\Services\MemberCashOutService;
use App\Services\OutboundPaymentService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    User::query()->delete();
    CashOutRequest::query()->delete();
    OutboundPayment::query()->delete();
    MembershipApplication::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'expense', 'name' => 'Master Expense', 'balance' => 5000, 'is_master' => true]);

    $this->admin = User::create([
        'name' => 'Admin',
        'email' => 'admin-remit@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $this->memberUser = User::create([
        'name' => 'Borrower',
        'email' => 'borrower-remit@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-REM1',
        'name' => 'Remit Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(2),
        'status' => 'active',
        'email' => 'borrower-remit@test.com',
    ]);

    MembershipApplication::create([
        'member_id' => $this->member->id,
        'name' => 'Remit Member',
        'email' => 'borrower-remit@test.com',
        'status' => 'approved',
        'application_type' => 'new',
        'iban' => 'SA0380000000608010167519',
        'bank_account_number' => '608010167519',
    ]);

    $this->accounting = app(AccountingService::class);
    $this->accounting->createMemberAccounts($this->member);
    $this->cashOuts = app(MemberCashOutService::class);
});

function seedMemberCashForRemittance(AccountingService $accounting, Member $member, float $amount): void
{
    AccountingService::withoutMemberCashCollection(function () use ($accounting, $member, $amount): void {
        $accounting->credit($member->cashAccount, $amount, 'Seed cash for remittance test');
    });
    $member->refresh();
}

test('accepting cash-out creates a pending remittance with payee bank snapshot', function () {
    seedMemberCashForRemittance($this->accounting, $this->member, 15000);

    $request = $this->cashOuts->submit($this->member, 1500, 'Wire home');
    $this->cashOuts->accept($request, $this->admin->id);

    $payment = OutboundPayment::query()->first();

    expect($payment)->not->toBeNull()
        ->and($payment->type)->toBe(OutboundPayment::TYPE_CASH_OUT)
        ->and($payment->status)->toBe(OutboundPayment::STATUS_PENDING)
        ->and((float) $payment->amount)->toBe(1500.0)
        ->and($payment->payee_name)->toBe('Remit Member')
        ->and($payment->payee_iban)->toBe('SA0380000000608010167519')
        ->and($payment->payee_bank_account_number)->toBe('608010167519')
        ->and($payment->bank_transaction_id)->toBe($request->fresh()->bank_transaction_id)
        ->and($payment->reason)->toBe('Wire home');
});

test('mark completed records check number for check payments', function () {
    seedMemberCashForRemittance($this->accounting, $this->member, 15000);

    $request = $this->cashOuts->submit($this->member, 800);
    $this->cashOuts->accept($request, $this->admin->id);

    $payment = OutboundPayment::query()->firstOrFail();

    $completed = app(OutboundPaymentService::class)->markCompleted($payment, [
        'payment_method' => OutboundPayment::METHOD_CHECK,
        'check_number' => 'CHK-4421',
        'payment_reference' => null,
        'completion_notes' => 'Handed to member',
    ], $this->admin->id);

    expect($completed->status)->toBe(OutboundPayment::STATUS_COMPLETED)
        ->and($completed->payment_method)->toBe(OutboundPayment::METHOD_CHECK)
        ->and($completed->check_number)->toBe('CHK-4421')
        ->and($completed->completed_by)->toBe($this->admin->id)
        ->and($completed->paid_at)->not->toBeNull();
});

test('check payment requires check number', function () {
    seedMemberCashForRemittance($this->accounting, $this->member, 15000);

    $request = $this->cashOuts->submit($this->member, 500);
    $this->cashOuts->accept($request, $this->admin->id);

    $payment = OutboundPayment::query()->firstOrFail();

    expect(fn () => app(OutboundPaymentService::class)->markCompleted($payment, [
        'payment_method' => OutboundPayment::METHOD_CHECK,
        'check_number' => '',
    ], $this->admin->id))->toThrow(InvalidArgumentException::class);
});

test('expense disbursement creates pending remittance', function () {
    $expense = Account::masterExpense() ?? Account::query()->where('type', 'expense')->where('is_master', true)->firstOrFail();

    $disbursement = app(MasterExpenseDisbursementService::class)->disburse(
        $expense,
        250.0,
        'Office supplies run',
        null,
        $this->admin->id,
    );

    $payment = OutboundPayment::query()->where('type', OutboundPayment::TYPE_EXPENSE_OUT)->first();

    expect($payment)->not->toBeNull()
        ->and((float) $payment->amount)->toBe(250.0)
        ->and($payment->reason)->toBe('Office supplies run')
        ->and($payment->bank_transaction_id)->toBe($disbursement->bank_transaction_id)
        ->and($payment->status)->toBe(OutboundPayment::STATUS_PENDING);
});

test('audit system remittances tab lists pending payee', function () {
    seedMemberCashForRemittance($this->accounting, $this->member, 15000);

    $request = $this->cashOuts->submit($this->member, 900, 'Payout checklist');
    $this->cashOuts->accept($request, $this->admin->id);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($this->admin, 'tenant')
        ->test(AuditSystemPage::class)
        ->call('setSideTab', 'remittances')
        ->assertSet('sideTab', 'remittances')
        ->assertSee(__('Outbound bank remittances'))
        ->assertSee('Remit Member');
});
