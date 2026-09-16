<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Meeting;
use App\Models\Tenant\Member;
use App\Models\Tenant\Motion;
use App\Models\Tenant\ProfitDistribution;
use App\Models\Tenant\User;
use App\Services\FiscalClose\FiscalCloseService;
use App\Services\Governance\MeetingMinutesPdfService;
use App\Services\ProfitDistribution\ProfitDistributionService;
use App\Services\Savings\MemberLoanReadinessService;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    User::query()->delete();
    ProfitDistribution::query()->delete();
    Motion::query()->delete();
    Meeting::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 1000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 1000, 'is_master' => true]);

    $this->admin = User::create([
        'name' => 'Gap Admin',
        'email' => 'gap-admin@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $this->member = Member::create([
        'user_id' => User::create([
            'name' => 'Gap Member',
            'email' => 'gap-member@test.com',
            'password' => bcrypt('password'),
            'is_admin' => false,
        ])->id,
        'member_number' => 'MEM-GAP1',
        'name' => 'Gap Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYears(3),
        'status' => 'active',
        'email' => 'gap-member@test.com',
    ]);

    Account::create([
        'type' => 'fund',
        'name' => 'Member Fund',
        'balance' => 5000,
        'is_master' => false,
        'member_id' => $this->member->id,
    ]);
});

test('fiscal close start is blocked by open profit distribution', function () {
    ProfitDistribution::query()->create([
        'status' => ProfitDistribution::STATUS_DRAFT,
        'period_start' => now()->subMonth()->toDateString(),
        'period_end' => now()->toDateString(),
        'amount' => 100,
        'allocation_method' => 'month_end_fund_balance',
        'created_by' => $this->admin->id,
    ]);

    expect(app(ProfitDistributionService::class)->hasBlockingOpenRuns())->toBeTrue();

    expect(fn () => app(FiscalCloseService::class)->startDraft(
        'FY2026',
        Carbon::parse('2026-12-31'),
    ))->toThrow(InvalidArgumentException::class);
});

test('loan readiness returns eligibility summary without mutating balances', function () {
    $before = (float) $this->member->fresh()->getFundBalance();
    $readiness = app(MemberLoanReadinessService::class)->forMember($this->member->fresh());

    expect($readiness)->toHaveKeys(['eligible', 'reasons', 'fund_balance', 'monthly_contribution', 'summary'])
        ->and($readiness['fund_balance'])->toBe(5000.0)
        ->and($this->member->fresh()->getFundBalance())->toBe($before);
});

test('meeting minutes pdf can be generated after minutes published', function () {
    $meeting = Meeting::query()->create([
        'title' => 'AGM',
        'status' => Meeting::STATUS_MINUTES_PUBLISHED,
        'minutes' => "Opened at 19:00.\nMotion 1 approved.",
        'minutes_published_at' => now(),
        'closed_at' => now(),
        'created_by' => $this->admin->id,
    ]);

    Motion::query()->create([
        'meeting_id' => $meeting->id,
        'title' => 'Approve fees',
        'type' => Motion::TYPE_GENERIC,
        'status' => Motion::STATUS_APPROVED,
        'yes_count' => 3,
        'no_count' => 0,
        'abstain_count' => 0,
        'eligible_voters' => 5,
        'quorum_met' => true,
        'created_by' => $this->admin->id,
    ]);

    $pdf = app(MeetingMinutesPdfService::class)->make($meeting->fresh(['motions']));
    $output = $pdf->output();

    expect($output)->not->toBeEmpty()
        ->and(strlen($output))->toBeGreaterThan(100);
});
