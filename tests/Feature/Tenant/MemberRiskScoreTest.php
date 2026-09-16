<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\Risk\MemberRiskScoreService;
use App\Support\RiskSettings;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    User::query()->delete();
    Loan::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 100000, 'is_master' => true]);

    $this->user = User::create([
        'name' => 'Risk Member',
        'email' => 'risk-member@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->user->id,
        'member_number' => 'MEM-RISK1',
        'name' => 'Risk Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(2),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);

    $this->risk = app(MemberRiskScoreService::class);
    $this->risk->forget($this->member->id);
});

test('risk score is explainable with factors and low band for healthy member', function () {
    // Fund cash enough for contribution
    app(AccountingService::class)->creditMemberCashWithMasterMirror(
        $this->member->cashAccount,
        2000,
        'seed',
        '(seed)',
        null,
        null,
        $this->member->id,
    );

    $this->risk->forget($this->member->id);
    $score = $this->risk->score($this->member);

    expect($score['score'])->toBeInt()
        ->and($score['band'])->toBeIn(['low', 'medium', 'high', 'critical'])
        ->and($score['factors'])->toBeArray();
});

test('active loan and low cash increase risk score', function () {
    Loan::create([
        'member_id' => $this->member->id,
        'amount' => 5000,
        'amount_requested' => 5000,
        'amount_approved' => 5000,
        'status' => 'active',
        'interest_rate' => 0,
        'term_months' => 10,
        'applied_at' => now()->subMonth(),
        'approved_at' => now()->subMonth(),
        'disbursed_at' => now()->subMonth(),
    ]);

    $this->risk->forget($this->member->id);
    $score = $this->risk->score($this->member);

    expect($score['score'])->toBeGreaterThan(0);
    expect(collect($score['factors'])->pluck('code'))->toContain('active_loans')
        ->and(collect($score['factors'])->pluck('code'))->toContain('cash_cover');
});

test('watchlist includes high band members', function () {
    // Force high score via multiple active loans
    foreach (range(1, 3) as $i) {
        Loan::create([
            'member_id' => $this->member->id,
            'amount' => 5000,
            'amount_requested' => 5000,
            'amount_approved' => 5000,
            'status' => 'active',
            'interest_rate' => 0,
            'term_months' => 10,
            'applied_at' => now()->subMonths($i),
            'approved_at' => now()->subMonths($i),
            'disbursed_at' => now()->subMonths($i),
        ]);
    }

    $this->risk->forget($this->member->id);
    Setting::set(RiskSettings::GROUP, 'high_score_threshold', '20');

    $list = $this->risk->watchlist('high');

    expect(collect($list)->pluck('member.id'))->toContain($this->member->id);
});
