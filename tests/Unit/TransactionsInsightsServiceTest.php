<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use App\Services\TransactionsInsightsService;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function (): void {
    $this->initializeTenancy();
    app()->setLocale('en');

    Transaction::query()->delete();
    Member::query()->delete();
    Account::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);

    $this->member = Member::create([
        'member_number' => 'MEM-TREND-01',
        'name' => 'Trend Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);

    $this->service = app(TransactionsInsightsService::class);
    $this->query = Transaction::query();
});

test('transactions insights default flow trend covers the last 30 days', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

    Transaction::create([
        'account_id' => $this->member->cashAccount->id,
        'member_id' => $this->member->id,
        'type' => 'credit',
        'amount' => 100,
        'balance_after' => 100,
        'description' => 'Recent credit',
        'transacted_at' => BusinessDay::now()->subDays(2),
    ]);

    Transaction::create([
        'account_id' => $this->member->cashAccount->id,
        'member_id' => $this->member->id,
        'type' => 'credit',
        'amount' => 200,
        'balance_after' => 300,
        'description' => 'Outside default window',
        'transacted_at' => BusinessDay::now()->subDays(45),
    ]);

    $snapshot = $this->service->snapshot($this->query);

    expect($snapshot['trend_title'])->toBe(trans_choice(':count-day flow trend|:count-day flow trend', 30, ['count' => 30]))
        ->and($snapshot['trend'])->toHaveCount(30)
        ->and(collect($snapshot['trend'])->sum('flow_total'))->toBe(100.0);

    Carbon::setTestNow();
});

test('transactions insights flow trend adapts to the selected date range', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

    Transaction::create([
        'account_id' => $this->member->cashAccount->id,
        'member_id' => $this->member->id,
        'type' => 'credit',
        'amount' => 150,
        'balance_after' => 150,
        'description' => 'Inside selected range',
        'transacted_at' => Carbon::parse('2026-03-10 09:00:00'),
    ]);

    Transaction::create([
        'account_id' => $this->member->cashAccount->id,
        'member_id' => $this->member->id,
        'type' => 'credit',
        'amount' => 500,
        'balance_after' => 650,
        'description' => 'Outside selected range',
        'transacted_at' => Carbon::parse('2026-06-01 09:00:00'),
    ]);

    $snapshot = $this->service->snapshot($this->query, [
        'date_range_transacted_at' => [
            'from' => '2026-03-01',
            'until' => '2026-03-31',
        ],
    ]);

    expect($snapshot['trend_title'])->toBe(trans_choice(':count-day flow trend|:count-day flow trend', 31, ['count' => 31]))
        ->and($snapshot['trend'])->toHaveCount(31)
        ->and(collect($snapshot['trend'])->sum('flow_total'))->toBe(150.0)
        ->and($snapshot['trend_period_label'])->toContain('Mar');

    Carbon::setTestNow();
});

test('transactions insights flow trend uses weekly buckets for longer ranges', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

    $snapshot = $this->service->snapshot($this->query, [
        'date_range_transacted_at' => [
            'from' => '2026-01-01',
            'until' => '2026-03-31',
        ],
    ]);

    expect($snapshot['trend'])->not->toBeEmpty()
        ->and(count($snapshot['trend']))->toBeLessThan(31)
        ->and($snapshot['trend_title'])->toBe(
            trans_choice(':count-week flow trend|:count-week flow trend', count($snapshot['trend']), [
                'count' => count($snapshot['trend']),
            ]),
        );

    Carbon::setTestNow();
});
