<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use Illuminate\Support\Collection;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Transaction::query()->delete();
    Member::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $this->accounting = app(AccountingService::class);
});

test('balanced journal validation allows same-direction bank import cash legs', function () {
    $member = Member::factory()->create(['status' => 'active']);
    $this->accounting->createMemberAccounts($member);

    $bankTxnId = 262;
    $morph = BankTransaction::class;

    $bank = Transaction::factory()->for(Account::masterBank())->credit()->create([
        'amount' => 3,
        'reference_type' => $morph,
        'reference_id' => $bankTxnId,
        'description' => 'Bank leg',
        'balance_after' => 3,
    ]);
    Transaction::factory()->for(Account::masterCash())->credit()->create([
        'amount' => 3,
        'reference_type' => $morph,
        'reference_id' => $bankTxnId,
        'description' => 'Cash leg',
        'balance_after' => 3,
    ]);
    $memberCash = Transaction::factory()->for($member->cashAccount)->credit()->create([
        'amount' => 3,
        'reference_type' => $morph,
        'reference_id' => $bankTxnId,
        'member_id' => $member->id,
        'description' => 'Member cash leg',
        'balance_after' => 3,
    ]);

    expect($this->accounting->validateBalancedJournalForReference($bank->fresh()))->toBeNull()
        ->and($this->accounting->validateBalancedJournalForReference($memberCash->fresh()))->toBeNull()
        ->and($this->accounting->isExpectedBankImportJournalReference($morph, $bankTxnId))->toBeTrue();
});

test('balanced journal validation still rejects mixed-direction bank transaction groups', function () {
    $bank = Transaction::factory()->for(Account::masterBank())->credit()->create([
        'amount' => 100,
        'reference_type' => BankTransaction::class,
        'reference_id' => 901,
        'description' => 'Bank credit',
        'balance_after' => 100,
    ]);
    Transaction::factory()->for(Account::masterCash())->debit()->create([
        'amount' => 40,
        'reference_type' => BankTransaction::class,
        'reference_id' => 901,
        'description' => 'Odd cash debit',
        'balance_after' => -40,
    ]);

    $message = $this->accounting->validateBalancedJournalForReference($bank->fresh());

    expect($message)->toBeString()
        ->and($message)->toContain(class_basename(BankTransaction::class))
        ->and($message)->toContain('#901');
});

test('same-direction journal helper requires a master bank leg', function () {
    $cashOnly = Collection::make([
        Transaction::factory()->for(Account::masterCash())->credit()->create([
            'amount' => 10,
            'reference_type' => BankTransaction::class,
            'reference_id' => 77,
            'balance_after' => 10,
        ]),
    ]);

    expect($this->accounting->isExpectedBankImportSameDirectionJournal($cashOnly))->toBeFalse();
});
