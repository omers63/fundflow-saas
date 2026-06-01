<?php

declare(strict_types=1);

use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\MemberCashOutInsightsService;
use App\Services\MemberCashOutService;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('member');

    Member::query()->delete();
    User::query()->delete();
    CashOutRequest::query()->delete();

    $this->member = Member::create([
        'member_number' => 'MEM-CO-INS',
        'name' => 'Cash Out Insights',
        'email' => 'cashout-insights@test.com',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);
    $this->member->cashAccount->update(['balance' => 2_000]);
});

test('cash-out insights returns empty without member', function () {
    expect(app(MemberCashOutInsightsService::class)->snapshot(null))->toBe([]);
});

test('cash-out insights summarizes requests and availability', function () {
    CashOutRequest::create([
        'member_id' => $this->member->id,
        'amount' => 300,
        'status' => 'pending',
        'notes' => 'Test',
    ]);

    CashOutRequest::create([
        'member_id' => $this->member->id,
        'amount' => 150,
        'status' => 'accepted',
        'notes' => 'Done',
        'reviewed_at' => now(),
    ]);

    $snapshot = app(MemberCashOutInsightsService::class)->snapshot($this->member);

    expect($snapshot)->toHaveKeys(['hero', 'kpis', 'sparkline', 'availability', 'recent', 'create_url'])
        ->and($snapshot['hero'])->toHaveKeys(['tone', 'title', 'subtitle'])
        ->and($snapshot['kpis'])->toHaveCount(6)
        ->and(collect($snapshot['kpis'])->firstWhere('label', __('Pending'))['value'])->toBe('1')
        ->and(collect($snapshot['kpis'])->firstWhere('label', __('Accepted'))['value'])->toBe('1')
        ->and($snapshot['recent'])->toHaveCount(2);

    expect(app(MemberCashOutService::class)->availableCashForWithdrawal($this->member))->toBe(1700.0)
        ->and($snapshot['hero']['tone'])->toBe('amber');
});
