<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Meeting;
use App\Models\Tenant\Member;
use App\Models\Tenant\Motion;
use App\Models\Tenant\ProfitDistribution;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Models\Tenant\Vote;
use App\Services\AccountingService;
use App\Services\Governance\MotionService;
use App\Services\ProfitDistribution\ProfitDistributionService;
use App\Support\GovernanceSettings;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    User::query()->delete();
    Transaction::query()->delete();
    ProfitDistribution::query()->delete();
    Vote::query()->delete();
    Motion::query()->delete();
    Meeting::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $this->admin = User::create([
        'name' => 'Dist Admin',
        'email' => 'dist-admin@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $this->accounting = app(AccountingService::class);

    $this->m1User = User::create([
        'name' => 'M1',
        'email' => 'm1-dist@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);
    $this->m2User = User::create([
        'name' => 'M2',
        'email' => 'm2-dist@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $this->m1 = Member::create([
        'user_id' => $this->m1User->id,
        'member_number' => 'MEM-D1',
        'name' => 'Member One',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYears(2),
        'status' => 'active',
    ]);
    $this->m2 = Member::create([
        'user_id' => $this->m2User->id,
        'member_number' => 'MEM-D2',
        'name' => 'Member Two',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYears(2),
        'status' => 'active',
    ]);

    $this->accounting->createMemberAccounts($this->m1);
    $this->accounting->createMemberAccounts($this->m2);

    $this->accounting->creditMemberFundWithMasterMirror(
        $this->m1->fundAccount,
        3000,
        'seed',
        '(seed)',
        null,
        null,
        $this->m1->id,
    );
    $this->accounting->creditMemberFundWithMasterMirror(
        $this->m2->fundAccount,
        1000,
        'seed',
        '(seed)',
        null,
        null,
        $this->m2->id,
    );

    GovernanceSettings::save([
        'distribution_motion_threshold' => 500,
        'setting_change_requires_motion' => false,
        'expense_motion_threshold' => 100000,
        'quorum_percent' => 50,
    ]);

    $this->distributions = app(ProfitDistributionService::class);
});

test('preview allocates by fund balance with residual on last member', function () {
    $run = $this->distributions->createDraft(
        now()->subMonth()->toDateString(),
        now()->toDateString(),
        100.0,
        $this->admin,
    );

    expect($run->item_count)->toBe(2);
    expect(round((float) $run->items->sum('amount'), 2))->toBe(100.0);

    $m1Share = (float) $run->items->firstWhere('member_id', $this->m1->id)->amount;
    $m2Share = (float) $run->items->firstWhere('member_id', $this->m2->id)->amount;

    expect($m1Share)->toBe(75.0)
        ->and($m2Share)->toBe(25.0);
});

test('post credits member and master fund mirrors and reverse restores', function () {
    $motions = app(MotionService::class);
    $meeting = $motions->createMeeting('Dist', $this->admin);
    $motion = $motions->createMotion(
        $meeting,
        'Distribute profits',
        Motion::TYPE_DISTRIBUTION_RUN,
        amountThreshold: 1000,
        creator: $this->admin,
    );
    $motions->openMotionForVoting($motion);
    $motions->castVote($motion->fresh(), $this->m1, Vote::CHOICE_YES);
    $motions->castVote($motion->fresh(), $this->m2, Vote::CHOICE_YES);
    $approvedMotion = $motions->closeAndResolve($motion->fresh());

    $run = $this->distributions->createDraft(
        now()->subMonth()->toDateString(),
        now()->toDateString(),
        100.0,
        $this->admin,
        $approvedMotion->id,
    );

    $this->distributions->approve($run->fresh(), $this->admin);

    $m1Before = (float) $this->m1->fundAccount->fresh()->balance;
    $masterBefore = (float) Account::masterFund()->fresh()->balance;

    $posted = $this->distributions->post($run->fresh());

    expect($posted->status)->toBe(ProfitDistribution::STATUS_POSTED)
        ->and((float) $posted->posted_total)->toBe(100.0);

    expect((float) $this->m1->fundAccount->fresh()->balance)->toBe($m1Before + 75.0);
    expect((float) Account::masterFund()->fresh()->balance)->toBe($masterBefore + 100.0);

    $txnCount = Transaction::query()
        ->where('reference_type', $posted->getMorphClass())
        ->where('reference_id', $posted->id)
        ->count();

    expect($txnCount)->toBeGreaterThan(0);

    $this->distributions->reverse($posted->fresh());

    expect((float) $this->m1->fundAccount->fresh()->balance)->toBe($m1Before);
    expect((float) Account::masterFund()->fresh()->balance)->toBe($masterBefore);
    expect($posted->fresh()->status)->toBe(ProfitDistribution::STATUS_REVERSED);
});

test('large distribution without motion is blocked', function () {
    $run = $this->distributions->createDraft(
        now()->subMonth()->toDateString(),
        now()->toDateString(),
        1000.0,
        $this->admin,
    );

    $this->distributions->approve($run->fresh(), $this->admin);
})->throws(InvalidArgumentException::class);
