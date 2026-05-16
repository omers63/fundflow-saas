<?php

declare(strict_types=1);

use App\Filament\Support\ViewActions\SplitAccountTransactionAction;
use App\Filament\Support\ViewActions\ViewAccountTransactionAction;
use App\Filament\Tenant\Resources\Accounts\RelationManagers\TransactionsRelationManager;
use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use Filament\Tables\Table;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Account::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    $this->accounting = app(AccountingService::class);
});

test('split replaces a credit with multiple lines without changing balance', function () {
    $account = Account::factory()->cash()->withBalance(0)->create();
    $original = $this->accounting->credit($account, 300, 'Bulk deposit');

    $this->accounting->splitTransaction($original, [
        ['amount' => 200, 'description' => 'Contribution portion'],
        ['amount' => 100, 'description' => 'Fee portion'],
    ]);

    $account->refresh();
    $lines = Transaction::query()->where('account_id', $account->id)->orderBy('id')->get();

    expect(Transaction::query()->find($original->id))->toBeNull()
        ->and((float) $account->balance)->toBe(300.0)
        ->and($lines)->toHaveCount(2)
        ->and($lines->pluck('description')->all())->toBe(['Contribution portion', 'Fee portion'])
        ->and($lines->every(fn (Transaction $line): bool => $line->type === 'credit'))->toBeTrue();
});

test('split replaces a debit with multiple lines without changing balance', function () {
    $account = Account::factory()->cash()->withBalance(500)->create();
    $original = $this->accounting->debit($account, 150, 'Single withdrawal');

    $this->accounting->splitTransaction($original, [
        ['amount' => 50, 'description' => 'Part A'],
        ['amount' => 100, 'description' => 'Part B'],
    ]);

    $account->refresh();

    expect((float) $account->balance)->toBe(350.0)
        ->and(Transaction::query()->where('account_id', $account->id)->count())->toBe(2);
});

test('split preserves reference and transacted_at on new lines', function () {
    $account = Account::factory()->fund()->withBalance(0)->create();
    $contribution = Contribution::factory()->create();
    $at = now()->subDays(3);

    $original = $this->accounting->credit($account, 80, 'Posted contribution', $contribution, $at);

    $this->accounting->splitTransaction($original, [
        ['amount' => 50, 'description' => 'Allocated'],
        ['amount' => 30, 'description' => 'Remainder'],
    ]);

    $line = Transaction::query()->where('account_id', $account->id)->first();

    expect($line)->not->toBeNull()
        ->and($line->reference_type)->toBe(Contribution::class)
        ->and($line->reference_id)->toBe($contribution->id)
        ->and($line->transacted_at?->toDateTimeString())->toBe($at->toDateTimeString());
});

test('split rejects parts that do not sum to the original amount', function () {
    $account = Account::factory()->cash()->create();
    $original = $this->accounting->credit($account, 100, 'Deposit');

    expect(fn () => $this->accounting->splitTransaction($original, [
        ['amount' => 60, 'description' => 'A'],
        ['amount' => 30, 'description' => 'B'],
    ]))->toThrow(InvalidArgumentException::class);
});

test('split cannot run on reversal entries or rows that already have a reversal', function () {
    $memberCash = Account::factory()->cash()->withBalance(500)->create();
    $original = $this->accounting->credit($memberCash, 100, 'Deposit');
    $reversal = $this->accounting->createReversalEntry($original, 'Mistake');

    expect($this->accounting->canSplitTransaction($original))->toBeFalse()
        ->and($this->accounting->canSplitTransaction($reversal))->toBeFalse();
});

test('split action is registered on tenant transaction tables', function () {
    $table = Table::make(
        app(TransactionsRelationManager::class)
    );

    $configured = ViewAccountTransactionAction::configure($table);

    expect($configured->hasAction('splitTransaction'))->toBeTrue()
        ->and(SplitAccountTransactionAction::categoryOptions())->toHaveKeys([
            'contribution',
            'loan',
            'repayment',
            'fee',
            'refund',
            'adjustment',
            'other',
        ]);
});
