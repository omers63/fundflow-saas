<?php

declare(strict_types=1);

use App\Filament\Support\MasterFeesHeaderActions;
use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\BankClearingMatchService;
use App\Services\MasterFeeDeductionService;
use App\Services\MasterFeeDisbursementService;
use App\Support\BankTransactionWorkflow;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Account::query()->delete();
    Member::query()->delete();
    MembershipApplication::query()->delete();
    BankTransaction::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 50_000, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);
});

test('deduct fee and disburse fee header actions are visible on master fees for admins', function () {
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin-fees-actions-'.uniqid('', true).'@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);
    $this->actingAs($admin, 'tenant');

    $account = Account::query()->where('type', 'fees')->where('is_master', true)->first();
    $actions = MasterFeesHeaderActions::make(fn () => $account);

    expect($actions)->toHaveCount(2)
        ->and($actions[0]->getName())->toBe('deductFee')
        ->and($actions[1]->getName())->toBe('disburseFee')
        ->and($actions[0]->isHidden())->toBeFalse()
        ->and($actions[1]->isHidden())->toBeFalse();
});

test('deduct fee debits member and master cash and credits master fees', function () {
    $member = Member::create([
        'member_number' => 'MEM-FEE-01',
        'name' => 'Fee Payer',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    app(AccountingService::class)->createMemberAccounts($member);
    app(AccountingService::class)->creditMemberCashWithMasterMirror(
        $member->cashAccount,
        500,
        'Seed',
        __('(seed mirror)'),
        null,
        null,
        $member->id,
    );

    $masterFees = Account::masterFees();
    $masterCashBefore = (float) Account::masterCash()->balance;

    app(MasterFeeDeductionService::class)->deduct($member, 150, 'Manual fee');

    $member->refresh();

    expect((float) $member->cashAccount->balance)->toBe(350.0)
        ->and((float) $masterFees->fresh()->balance)->toBe(150.0)
        ->and((float) Account::masterCash()->fresh()->balance)->toBe($masterCashBefore - 150);
});

test('deduct fee clears subscription fee arrears when fully paid', function () {
    $member = Member::create([
        'member_number' => 'MEM-FEE-02',
        'name' => 'Subscriber',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    app(AccountingService::class)->createMemberAccounts($member);
    app(AccountingService::class)->creditMemberCashWithMasterMirror(
        $member->cashAccount,
        1_000,
        'Seed',
        __('(seed mirror)'),
        null,
        null,
        $member->id,
    );

    $application = MembershipApplication::create([
        'name' => $member->name,
        'email' => 'sub-'.uniqid('', true).'@test.com',
        'member_id' => $member->id,
        'application_type' => 'new',
        'status' => 'approved',
        'membership_fee_amount' => 200,
        'membership_fee_required_amount' => 500,
        'rejection_reason' => __('Subscription fee arrears: :amount', ['amount' => number_format(300, 2)]),
    ]);

    app(MasterFeeDeductionService::class)->deduct($member, 300, 'Subscription catch-up');

    $application->refresh();

    expect((float) $application->membership_fee_amount)->toBe(500.0)
        ->and($application->rejection_reason)->toBeNull()
        ->and((float) Account::masterFees()->fresh()->balance)->toBe(300.0);
});

test('disburse fee debits master fees only and creates uncleared bank line', function () {
    $masterFees = Account::masterFees();
    $masterFees->update(['balance' => 800]);

    $disbursement = app(MasterFeeDisbursementService::class)->disburse(
        $masterFees,
        250,
        'Fee payout',
    );

    expect((float) $masterFees->fresh()->balance)->toBe(550.0)
        ->and((float) Account::masterCash()->balance)->toBe(50_000.0)
        ->and($disbursement->bankTransaction)->not->toBeNull()
        ->and($disbursement->bankTransaction->is_cleared)->toBeFalse()
        ->and((float) $disbursement->bankTransaction->amount)->toBe(-250.0);
});

test('fee disbursement matched import is match-only without master bank ledger', function () {
    Account::masterFees()->update(['balance' => 1_000]);

    $disbursement = app(MasterFeeDisbursementService::class)->disburse(
        Account::masterFees(),
        120,
        'Refund check',
    );

    $uncleared = $disbursement->bankTransaction;
    $masterBank = Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $statement = BankStatement::create([
        'filename' => 'fee-disburse-match.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $imported = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Fee paid',
        'amount' => -120,
        'status' => 'imported',
        'hash' => md5('fee-disburse-match-import'),
        'is_cleared' => false,
    ]);

    app(BankClearingMatchService::class)->clearMatchPair($uncleared, $imported);

    $imported = $imported->fresh();

    expect(BankTransactionWorkflow::canPostToCash($imported))->toBeFalse()
        ->and($imported->fee_disbursement_id)->toBe($disbursement->id)
        ->and($imported->master_bank_transaction_id)->toBeNull()
        ->and((float) $masterBank->fresh()->balance)->toBe(0.0);
});
