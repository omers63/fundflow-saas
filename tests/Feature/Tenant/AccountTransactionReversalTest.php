<?php

declare(strict_types=1);

use App\Filament\Support\ViewActions\ReverseAccountTransactionAction;
use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\LoanService;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Account::query()->delete();
    Member::query()->delete();
    User::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 10_000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 100_000, 'is_master' => true]);
});

test('reverse action is visible for any account when user is admin', function () {
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin-reverse-'.uniqid('', true).'@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);
    $this->actingAs($admin, 'tenant');

    $memberCash = Account::factory()->cash()->create();
    $memberFund = Account::factory()->fund()->create();
    $masterCash = Account::masterCash();

    $cashTxn = Transaction::factory()->for($memberCash)->create([
        'type' => 'credit',
        'amount' => 100,
        'balance_after' => 100,
        'description' => 'Cash line',
        'transacted_at' => now(),
    ]);

    expect(ReverseAccountTransactionAction::canReverse($cashTxn))->toBeTrue()
        ->and(ReverseAccountTransactionAction::canReverse(
            Transaction::factory()->for($memberFund)->create([
                'type' => 'credit',
                'amount' => 50,
                'balance_after' => 50,
                'description' => 'Fund line',
                'transacted_at' => now(),
            ])
        ))->toBeTrue()
        ->and(ReverseAccountTransactionAction::canReverse(
            Transaction::factory()->for($masterCash)->create([
                'type' => 'credit',
                'amount' => 50,
                'balance_after' => 10_050,
                'description' => 'Master line',
                'transacted_at' => now(),
            ])
        ))->toBeTrue();
});

test('full source reversal reverses all ledger lines for the same reference', function () {
    $member = Member::factory()->create();
    app(AccountingService::class)->createMemberAccounts($member);

    $masterFund = Account::masterFund();
    $memberFund = $member->fundAccount;
    $memberCash = $member->cashAccount;

    $loan = Loan::factory()->for($member)->create([
        'amount' => 1000,
        'amount_approved' => 1000,
        'amount_disbursed' => 0,
        'status' => 'approved',
    ]);

    app(LoanService::class)->disburseLoan($loan);

    $memberCashLine = Transaction::query()
        ->where('account_id', $memberCash->id)
        ->where('reference_type', Loan::class)
        ->where('reference_id', $loan->id)
        ->first();

    expect($memberCashLine)->not->toBeNull();

    $accounting = app(AccountingService::class);

    expect($accounting->canUseFullSourceReversal($memberCashLine))->toBeTrue()
        ->and($accounting->countRelatedLedgerEntries($memberCashLine))->toBeGreaterThanOrEqual(3);

    $manualLine = Transaction::factory()->for($memberCash)->create([
        'type' => 'credit',
        'amount' => 25,
        'balance_after' => 25,
        'description' => 'Manual',
        'reference_type' => null,
        'reference_id' => null,
        'transacted_at' => now(),
    ]);

    expect($accounting->canUseFullSourceReversal($manualLine))->toBeFalse();

    $count = $accounting->createFullSourceReversal($memberCashLine, 'Undo disbursement');

    expect($count)->toBeGreaterThanOrEqual(3);

    $masterFund->refresh();
    $memberFund->refresh();
    $memberCash->refresh();

    expect((float) $masterFund->balance)->toBe(100_000.0)
        ->and((float) Account::masterCash()->fresh()->balance)->toBe(10_000.0)
        ->and((float) $memberFund->balance)->toBe(0.0)
        ->and((float) $memberCash->balance)->toBe(0.0);
});

test('create reversal entry posts opposite type on member cash', function () {
    $memberCash = Account::factory()->cash()->withBalance(500)->create();
    $accounting = app(AccountingService::class);

    $original = null;
    $reversal = null;

    AccountingService::withoutMemberCashCollection(function () use ($accounting, $memberCash, &$original, &$reversal): void {
        $original = $accounting->credit($memberCash, 200, 'Deposit');
        $reversal = $accounting->createReversalEntry($original, 'Correction');
    });

    $memberCash->refresh();
    $original->refresh();

    expect($reversal->type)->toBe('debit')
        ->and((float) $reversal->amount)->toBe(200.0)
        ->and($reversal->reference_type)->toBe(Transaction::class)
        ->and($reversal->reference_id)->toBe($original->id)
        ->and((float) $memberCash->balance)->toBe(500.0)
        ->and(app(AccountingService::class)->hasExistingReversal($original))->toBeTrue();
});

test('create reversal entry rejects insufficient cash for debit reversal', function () {
    $memberCash = Account::factory()->cash()->withBalance(50)->create();
    $accounting = app(AccountingService::class);

    $original = null;

    AccountingService::withoutMemberCashCollection(function () use ($accounting, $memberCash, &$original): void {
        $original = $accounting->credit($memberCash, 100, 'Deposit');
        $accounting->debit($memberCash->fresh(), 120, 'Withdrawal');
    });

    $accounting->createReversalEntry($original->fresh(), 'Too much');
})->throws(InvalidArgumentException::class);

test('full source reversal of a contribution does not double-post master pool mirrors', function () {
    $member = Member::factory()->create();
    $accounting = app(AccountingService::class);
    $accounting->createMemberAccounts($member);

    AccountingService::withoutMemberCashCollection(function () use ($accounting, $member): void {
        $accounting->creditMemberCashWithMasterMirror(
            $member->fresh()->cashAccount,
            800,
            'Seed cash',
            '(test)',
            $member,
            now(),
            $member->id,
        );
    });

    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => now()->startOfMonth()->toDateString(),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 500,
        'status' => 'posted',
        'posted_at' => now(),
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
    ]);

    $accounting->postContributionPrincipal($contribution, 500);
    $accounting->reverseAllSourceEntries($contribution, 'Undo collection');

    expect(AccountingService::masterPoolMirrorInProgress())->toBeFalse()
        ->and($accounting->duplicateMasterPoolReversalMirrors())->toHaveCount(0)
        ->and((float) Account::masterCash()->fresh()->balance)->toBe(10_800.0)
        ->and((float) Account::masterFund()->fresh()->balance)->toBe(100_000.0)
        ->and((float) $member->fresh()->cashAccount->balance)->toBe(800.0)
        ->and((float) $member->fresh()->fundAccount->balance)->toBe(0.0);
});

test('repair reverses duplicate master pool mirrors left by a double-mirrored source reverse', function () {
    $member = Member::factory()->create();
    $accounting = app(AccountingService::class);
    $accounting->createMemberAccounts($member);

    AccountingService::withoutMemberCashCollection(function () use ($accounting, $member): void {
        $accounting->creditMemberCashWithMasterMirror(
            $member->fresh()->cashAccount,
            800,
            'Seed cash',
            '(test)',
            $member,
            now(),
            $member->id,
        );
    });

    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => now()->startOfMonth()->toDateString(),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 500,
        'status' => 'posted',
        'posted_at' => now(),
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
    ]);

    $accounting->postContributionPrincipal($contribution, 500);

    $masterCashAfterPost = (float) Account::masterCash()->fresh()->balance;
    $masterFundAfterPost = (float) Account::masterFund()->fresh()->balance;

    $siblings = Transaction::query()
        ->where('reference_type', Contribution::class)
        ->where('reference_id', $contribution->id)
        ->with('account')
        ->orderByRaw("CASE WHEN type = 'debit' THEN 0 ELSE 1 END")
        ->orderBy('id')
        ->get();

    AccountingService::withoutMemberCashCollection(function () use ($accounting, $siblings): void {
        foreach ($siblings as $entry) {
            $accounting->createReversalEntry($entry, 'Simulate old full-source reverse');
        }
    });

    expect(AccountingService::masterPoolMirrorInProgress())->toBeFalse()
        ->and($accounting->duplicateMasterPoolReversalMirrors())->toHaveCount(2)
        ->and((float) Account::masterCash()->fresh()->balance)->toBe($masterCashAfterPost + 1_000.0)
        ->and((float) Account::masterFund()->fresh()->balance)->toBe($masterFundAfterPost - 1_000.0);

    expect($accounting->repairDuplicateMasterPoolReversalMirrors())->toBe(2)
        ->and($accounting->duplicateMasterPoolReversalMirrors())->toHaveCount(0)
        ->and((float) Account::masterCash()->fresh()->balance)->toBe($masterCashAfterPost + 500.0)
        ->and((float) Account::masterFund()->fresh()->balance)->toBe($masterFundAfterPost - 500.0)
        ->and((float) $member->fresh()->cashAccount->balance)->toBe(800.0)
        ->and((float) $member->fresh()->fundAccount->balance)->toBe(0.0);
});
