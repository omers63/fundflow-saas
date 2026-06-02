<?php

declare(strict_types=1);

use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\MemberFundPostingInsightsService;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function (): void {
    $this->initializeTenancy();
    Filament::setCurrentPanel('member');

    Member::query()->delete();
    User::query()->delete();
    FundPosting::query()->delete();

    $memberUser = User::create([
        'name' => 'Posting Member',
        'email' => 'posting-member@test.com',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $member = Member::create([
        'user_id' => $memberUser->id,
        'member_number' => 'MEM-FP-INS',
        'name' => 'Posting Member',
        'email' => 'posting-member@test.com',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    app()->instance('test.member.fund-posting-insights', $member);
});

it('builds member fund posting snapshot with sparkline and totals', function (): void {
    /** @var Member $member */
    $member = app('test.member.fund-posting-insights');

    FundPosting::create([
        'member_id' => $member->id,
        'posting_date' => now()->toDateString(),
        'amount' => 1500,
        'status' => 'pending',
    ]);

    FundPosting::create([
        'member_id' => $member->id,
        'posting_date' => now()->toDateString(),
        'amount' => 1200,
        'status' => 'accepted',
    ]);

    $snapshot = app(MemberFundPostingInsightsService::class)->snapshot($member);

    expect($snapshot)->toHaveKeys(['pending', 'accepted', 'recent', 'sparkline'])
        ->and($snapshot['pending'])->toBe(1)
        ->and($snapshot['accepted'])->toBe(1)
        ->and($snapshot['sparkline'])->toHaveCount(6);
});
