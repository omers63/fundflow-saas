<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Support\MemberAccountDeletion;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Account::query()->delete();
    Member::query()->delete();
});

it('allows deleting a zero-balance member account', function () {
    $member = Member::factory()->create();
    $account = Account::create([
        'member_id' => $member->id,
        'type' => 'cash',
        'name' => $member->name.' - Cash',
        'balance' => 0,
        'is_master' => false,
    ]);

    expect(MemberAccountDeletion::canDelete($account))->toBeTrue();

    MemberAccountDeletion::ensureCanDelete($account);
    $account->delete();

    expect(Account::find($account->id))->toBeNull();
});

it('blocks deleting accounts with a non-zero balance', function () {
    $account = Account::create([
        'member_id' => Member::factory()->create()->id,
        'type' => 'fund',
        'name' => 'Fund',
        'balance' => 100,
        'is_master' => false,
    ]);

    expect(MemberAccountDeletion::canDelete($account))->toBeFalse()
        ->and(MemberAccountDeletion::blockReason($account))
        ->toContain('zero');
});

it('blocks deleting master and loan ledger accounts', function () {
    $master = Account::create([
        'type' => 'cash',
        'name' => 'Master Cash',
        'balance' => 0,
        'is_master' => true,
    ]);

    $member = Member::factory()->create();
    $loan = Loan::factory()->for($member)->create();

    $loanAccount = Account::create([
        'member_id' => $member->id,
        'loan_id' => $loan->id,
        'type' => Account::TYPE_LOAN,
        'name' => 'Loan ledger',
        'balance' => 0,
        'is_master' => false,
    ]);

    expect(MemberAccountDeletion::canDelete($master))->toBeFalse()
        ->and(MemberAccountDeletion::canDelete($loanAccount))->toBeFalse();
});
