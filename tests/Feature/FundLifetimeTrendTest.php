<?php

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\FundLifetimeTrendService;
use App\Services\MemberLifetimeTrendService;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

it('adds every member lifetime chart together at fund level', function () {
    $this->initializeTenancy();
    Cache::flush();
    Contribution::query()->delete();
    Member::query()->delete();
    User::query()->delete();

    foreach ([1, 2] as $n) {
        $user = User::create([
            'name' => "Fund Trend {$n}", 'email' => "fund-trend-{$n}@fund.test", 'password' => bcrypt('password'),
            'email_verified_at' => now(), 'is_admin' => false, 'preferred_locale' => 'en',
        ]);
        $member = Member::create([
            'user_id' => $user->id, 'member_number' => "MEM-FT{$n}", 'name' => "Fund Trend {$n}", 'email' => "fund-trend-{$n}@fund.test",
            'monthly_contribution_amount' => 500 * $n, 'joined_at' => now()->subMonths(3)->startOfMonth(), 'status' => 'active',
        ]);
        Contribution::create([
            'member_id' => $member->id, 'period' => now()->subMonth()->startOfMonth()->toDateString(),
            'amount' => 500 * $n, 'status' => 'posted', 'posted_at' => now()->subMonth()->startOfMonth()->addDays(9),
        ]);
    }

    $fund = app(FundLifetimeTrendService::class)->trend();
    $members = Member::query()->get()->map(fn (Member $m): array => app(MemberLifetimeTrendService::class)->buildFresh($m));

    foreach (['contributionsPosted', 'contributionsExpected', 'repaymentsPaid', 'repaymentsDue', 'disbursed'] as $key) {
        expect(round(array_sum(array_column($fund, $key)), 2))
            ->toBe(round((float) $members->sum(fn (array $rows): float => array_sum(array_column($rows, $key))), 2));
    }

    expect(array_sum(array_column($fund, 'contributionsPosted')))->toBe(1500.0)
        ->and(collect($fund)->pluck('contributionsPostedOn')->flatten(1)->sum('count'))->toBe(2);
});
