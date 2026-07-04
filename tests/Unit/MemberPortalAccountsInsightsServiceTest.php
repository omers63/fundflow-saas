<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\MemberPortalAccountsInsightsService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('member');

    Member::query()->delete();
    User::query()->delete();
    Account::query()->delete();

    $this->user = User::create([
        'name' => 'Account Member',
        'email' => 'accounts@member.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->user->id,
        'member_number' => 'MEM-ACC01',
        'name' => 'Account Member',
        'email' => 'accounts@member.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);
    $this->member->load(['cashAccount', 'fundAccount']);
});

test('member portal accounts insights snapshot includes balances and kpis', function () {
    Transaction::factory()->create([
        'account_id' => $this->member->cashAccount->id,
        'type' => 'credit',
        'amount' => 500,
        'transacted_at' => Carbon::now()->subDays(3),
    ]);

    $snapshot = app(MemberPortalAccountsInsightsService::class)->snapshot($this->member);

    expect($snapshot)->toHaveKeys(['hero', 'kpis', 'trend', 'accounts', 'sparkline', 'forecast'])
        ->and($snapshot['kpis'])->toHaveCount(6)
        ->and($snapshot['accounts']['cash']['balance'])->not->toBeEmpty()
        ->and($snapshot['trend'])->toHaveCount(6)
        ->and($snapshot['forecast'])->toHaveKeys([
            'days_remaining',
            'withdrawable_cash',
            'pending_cash_out_amount',
            'emi_reserve',
        ]);
});

test('member portal accounts insights flags negative cash and fund balances for styling', function () {
    $this->member->cashAccount()->update(['balance' => -250]);
    $this->member->fundAccount()->update(['balance' => -750]);

    $snapshot = app(MemberPortalAccountsInsightsService::class)->snapshot(
        $this->member->fresh(['cashAccount', 'fundAccount']),
    );

    $cashKpi = collect($snapshot['kpis'])->firstWhere('label', __('Cash'));
    $fundKpi = collect($snapshot['kpis'])->firstWhere('label', __('Fund'));
    $netKpi = collect($snapshot['kpis'])->firstWhere('label', __('Net worth'));

    expect($snapshot['cash_negative'])->toBeTrue()
        ->and($snapshot['fund_negative'])->toBeTrue()
        ->and($cashKpi['accent'])->toBe('rose')
        ->and($cashKpi['value_class'])->toContain('text-rose-600')
        ->and($fundKpi['accent'])->toBe('rose')
        ->and($fundKpi['value_class'])->toContain('text-rose-600')
        ->and($netKpi['accent'])->toBe('rose')
        ->and($netKpi['value_class'])->toContain('text-rose-600');
});

test('member portal accounts insights returns empty without member', function () {
    expect(app(MemberPortalAccountsInsightsService::class)->snapshot(null))->toBe([]);
});
