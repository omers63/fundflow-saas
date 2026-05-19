<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\MemberContributionInsightsService;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('member');

    Account::query()->delete();
    Contribution::query()->delete();
    Member::query()->delete();
    User::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);

    $this->memberUser = User::create([
        'name' => 'Contrib Member',
        'email' => 'contrib@insights.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-CNT01',
        'name' => 'Contrib Member',
        'email' => 'contrib@insights.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);
});

test('member contribution insights snapshot includes cycle and trend data', function () {
    [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();
    $period = Contribution::periodDate($month, $year);

    Contribution::factory()->for($this->member)->create([
        'period' => $period,
        'amount' => 1000,
        'status' => 'pending',
        'is_late' => false,
    ]);

    Contribution::factory()->for($this->member)->posted()->create([
        'period' => now()->subMonths(2)->startOfMonth()->toDateString(),
        'amount' => 1000,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
    ]);

    $snapshot = app(MemberContributionInsightsService::class)->snapshot($this->member);

    expect($snapshot)->toHaveKeys(['hero', 'kpis', 'open_cycle', 'trend', 'consistency', 'streak', 'summary'])
        ->and($snapshot['kpis'])->toHaveCount(6)
        ->and($snapshot['trend'])->toHaveCount(6)
        ->and($snapshot['open_cycle']['period_label'])->not->toBeEmpty()
        ->and($snapshot['summary']['pending_count'])->toBe(1)
        ->and($snapshot['summary']['posted_count'])->toBe(1);
});

test('member contribution insights returns empty array without member', function () {
    expect(app(MemberContributionInsightsService::class)->snapshot(null))->toBe([]);
});
