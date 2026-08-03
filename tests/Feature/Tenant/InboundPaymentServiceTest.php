<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\AuditSystemPage;
use App\Models\Tenant\Account;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\InboundPayment;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\FundPostingService;
use App\Services\InboundPaymentService;
use App\Services\MasterInvestReturnService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    User::query()->delete();
    FundPosting::query()->delete();
    InboundPayment::query()->delete();
    MembershipApplication::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'invest', 'name' => 'Master Invest', 'balance' => 0, 'is_master' => true]);

    $this->admin = User::create([
        'name' => 'Admin',
        'email' => 'admin-inbound@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $this->memberUser = User::create([
        'name' => 'Depositor',
        'email' => 'depositor-inbound@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-INB1',
        'name' => 'Inbound Payer',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(2),
        'status' => 'active',
        'email' => 'depositor-inbound@test.com',
    ]);

    MembershipApplication::create([
        'member_id' => $this->member->id,
        'name' => 'Inbound Payer',
        'email' => 'depositor-inbound@test.com',
        'status' => 'approved',
        'application_type' => 'new',
        'iban' => 'SA0380000000608010167519',
        'bank_account_number' => '608010167519',
    ]);

    $this->accounting = app(AccountingService::class);
    $this->accounting->createMemberAccounts($this->member);
    $this->fundPostings = app(FundPostingService::class);
});

test('submitting a deposit creates a pending inbound remittance with payer bank snapshot', function () {
    $posting = $this->fundPostings->submit(
        $this->member,
        2000.0,
        now()->toDateString(),
        'REF-DEP-1',
        null,
        'Monthly top-up',
    );

    $payment = InboundPayment::query()->first();

    expect($payment)->not->toBeNull()
        ->and($payment->type)->toBe(InboundPayment::TYPE_DEPOSIT)
        ->and($payment->status)->toBe(InboundPayment::STATUS_PENDING)
        ->and((float) $payment->amount)->toBe(2000.0)
        ->and($payment->payer_name)->toBe('Inbound Payer')
        ->and($payment->payer_iban)->toBe('SA0380000000608010167519')
        ->and($payment->payer_bank_account_number)->toBe('608010167519')
        ->and($payment->bank_transaction_id)->toBe($posting->bank_transaction_id)
        ->and($payment->reason)->toBe('Monthly top-up');
});

test('rejecting a deposit cancels the inbound remittance', function () {
    $posting = $this->fundPostings->submit(
        $this->member,
        500.0,
        now()->toDateString(),
    );

    $this->fundPostings->reject($posting, $this->admin->id, 'Duplicate');

    $payment = InboundPayment::query()->firstOrFail();

    expect($payment->status)->toBe(InboundPayment::STATUS_CANCELLED)
        ->and($payment->completion_notes)->toBe('Duplicate');
});

test('mark completed records check number for check receipts', function () {
    $this->fundPostings->submit($this->member, 750.0, now()->toDateString());

    $payment = InboundPayment::query()->firstOrFail();

    $completed = app(InboundPaymentService::class)->markCompleted($payment, [
        'payment_method' => InboundPayment::METHOD_CHECK,
        'check_number' => 'IN-991',
        'completion_notes' => 'Received at front desk',
    ], $this->admin->id);

    expect($completed->status)->toBe(InboundPayment::STATUS_COMPLETED)
        ->and($completed->payment_method)->toBe(InboundPayment::METHOD_CHECK)
        ->and($completed->check_number)->toBe('IN-991')
        ->and($completed->received_at)->not->toBeNull()
        ->and($completed->completed_by)->toBe($this->admin->id);
});

test('invest return creates pending inbound remittance', function () {
    $invest = Account::query()->where('type', 'invest')->where('is_master', true)->firstOrFail();

    $return = app(MasterInvestReturnService::class)->record(
        $invest,
        400.0,
        'Q2 dividend',
        null,
        $this->admin->id,
    );

    $payment = InboundPayment::query()->where('type', InboundPayment::TYPE_INVEST_RETURN)->first();

    expect($payment)->not->toBeNull()
        ->and((float) $payment->amount)->toBe(400.0)
        ->and($payment->reason)->toBe('Q2 dividend')
        ->and($payment->bank_transaction_id)->toBe($return->bank_transaction_id)
        ->and($payment->status)->toBe(InboundPayment::STATUS_PENDING);
});

test('audit system inbound remittances tab lists pending payer', function () {
    $this->fundPostings->submit(
        $this->member,
        900.0,
        now()->toDateString(),
        null,
        null,
        'Receipt checklist',
    );

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($this->admin, 'tenant')
        ->test(AuditSystemPage::class)
        ->call('setSideTab', 'inbound_remittances')
        ->assertSet('sideTab', 'inbound_remittances')
        ->assertSee(__('Inbound bank remittances'))
        ->assertSee('Inbound Payer');
});
