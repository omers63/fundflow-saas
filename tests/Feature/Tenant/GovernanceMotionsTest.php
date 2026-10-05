<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Meeting;
use App\Models\Tenant\Member;
use App\Models\Tenant\Motion;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Models\Tenant\Vote;
use App\Services\AccountingService;
use App\Services\Governance\MotionEnforcementGate;
use App\Services\Governance\MotionService;
use App\Support\GovernanceSettings;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    User::query()->delete();
    Vote::query()->delete();
    Motion::query()->delete();
    Meeting::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 100000, 'is_master' => true]);

    $this->admin = User::create([
        'name' => 'Gov Admin',
        'email' => 'gov-admin@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $this->memberUser = User::create([
        'name' => 'Voter',
        'email' => 'voter@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-GOV1',
        'name' => 'Voter',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);

    GovernanceSettings::save([
        'quorum_percent' => 50,
        'board_only_voting' => false,
        'expense_motion_threshold' => 1000,
        'setting_change_requires_motion' => true,
        'protected_setting_groups' => 'loan,contribution',
        'distribution_motion_threshold' => 5000,
    ]);

    $this->motions = app(MotionService::class);
});

test('members can vote and quorum resolves approval', function () {
    $meeting = $this->motions->createMeeting('AGM', $this->admin);
    $motion = $this->motions->createMotion($meeting, 'Raise interest', Motion::TYPE_SETTING_CHANGE, payload: [
        'group' => 'loan',
        'key' => 'interest_rate',
    ], creator: $this->admin);

    $this->motions->openMotionForVoting($motion);
    $this->motions->castVote($motion->fresh(), $this->member, Vote::CHOICE_YES);
    $resolved = $this->motions->closeAndResolve($motion->fresh());

    expect($resolved->status)->toBe(Motion::STATUS_APPROVED)
        ->and($resolved->quorum_met)->toBeTrue()
        ->and((int) $resolved->yes_count)->toBe(1);
});

test('protected setting change is blocked without approved motion', function () {
    Setting::set('loan', 'interest_rate', '12');
})->throws(InvalidArgumentException::class);

test('protected setting change succeeds with covering approved motion', function () {
    $meeting = $this->motions->createMeeting('Rates', $this->admin);
    $motion = $this->motions->createMotion($meeting, 'Set rate', Motion::TYPE_SETTING_CHANGE, payload: [
        'group' => 'loan',
        'key' => 'interest_rate',
    ], creator: $this->admin);
    $this->motions->openMotionForVoting($motion);
    $this->motions->castVote($motion->fresh(), $this->member, Vote::CHOICE_YES);
    $approved = $this->motions->closeAndResolve($motion->fresh());

    Setting::set('loan', 'interest_rate', '12', $approved->id);

    expect(Setting::get('loan', 'interest_rate'))->toBe('12');
});

test('large expense requires approved motion', function () {
    app(MotionEnforcementGate::class)->assertExpenseAllowed(5000.0, null);
})->throws(InvalidArgumentException::class);

test('cannot add motions after minutes published', function () {
    $meeting = $this->motions->createMeeting('Done', $this->admin);
    $this->motions->openMeeting($meeting);
    $this->motions->publishMinutes($meeting->fresh(), 'Minutes text');

    $this->motions->createMotion($meeting->fresh(), 'Late motion');
})->throws(InvalidArgumentException::class);
