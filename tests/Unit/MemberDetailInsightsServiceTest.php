<?php

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\MemberDetailInsightsService;
use App\Support\Insights\InsightFormatter;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    Member::query()->delete();
});

test('member detail insights snapshot includes balances and lifecycle', function () {
    $member = Member::factory()->create([
        'name' => 'Insight Member',
        'status' => 'active',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subMonths(6),
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    $member->cashAccount?->update(['balance' => 1500]);
    $member->fundAccount?->update(['balance' => 2500]);

    $snapshot = app(MemberDetailInsightsService::class)->snapshot($member->fresh());

    expect($snapshot['member']['name'])->toBe('Insight Member')
        ->and($snapshot['balances']['cash']['amount'])->toBe(1500.0)
        ->and($snapshot['balances']['fund']['amount'])->toBe(2500.0)
        ->and($snapshot['snapshot'])->toHaveKeys(['status_title', 'monthly_formatted', 'cycle_summary'])
        ->and($snapshot['metrics'])->toBeArray()
        ->and($snapshot['kpis'])->toHaveCount(10)
        ->and($snapshot['steps'])->not->toBeEmpty()
        ->and($snapshot['steps'][0])->toHaveKey('description')
        ->and($snapshot['cycle']['period_label'])->toBeString()
        ->and($snapshot['trend'])->toHaveCount(6)
        ->and($snapshot['sparkline'])->toHaveCount(8)
        ->and($snapshot['relation_summaries'])->toHaveCount(4);
});

test('member detail insights snapshot includes lifetime disbursed loan total', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 1000,
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    Loan::factory()->for($member)->create([
        'status' => 'completed',
        'amount_disbursed' => 25_000,
        'disbursed_at' => now()->subYear(),
    ]);

    Loan::factory()->for($member)->create([
        'status' => 'active',
        'amount_disbursed' => 15_000,
        'disbursed_at' => now()->subMonth(),
    ]);

    Loan::factory()->for($member)->create([
        'status' => 'pending',
        'amount_disbursed' => 0,
        'disbursed_at' => null,
    ]);

    $snapshot = app(MemberDetailInsightsService::class)->snapshot($member->fresh());
    $lifetimeKpi = collect($snapshot['kpis'])->firstWhere('key', 'lifetime_disbursed');

    expect($lifetimeKpi)->not->toBeNull()
        ->and($lifetimeKpi['label'])->toBe(__('Lifetime disbursed'))
        ->and($lifetimeKpi['value'])->toBe(InsightFormatter::compactAmount(40_000.0))
        ->and($lifetimeKpi['sub'])->toBe(trans_choice(':count loan disbursed|:count loans disbursed', 2, ['count' => 2]))
        ->and($lifetimeKpi['url'])->toBeString()->not->toBeEmpty();
});

test('member detail insights snapshot includes lifetime contribution and repayment totals with money subtitles', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 1000,
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    Contribution::query()->create([
        'member_id' => $member->id,
        'period' => now()->subMonths(2)->startOfMonth(),
        'amount' => 2_500,
        'status' => 'posted',
        'posted_at' => now()->subMonths(2),
    ]);
    Contribution::query()->create([
        'member_id' => $member->id,
        'period' => now()->subMonth()->startOfMonth(),
        'amount' => 3_500,
        'status' => 'posted',
        'posted_at' => now()->subMonth(),
    ]);

    $loan = Loan::factory()->for($member)->create([
        'status' => 'active',
        'amount_disbursed' => 10_000,
        'disbursed_at' => now()->subMonth(),
    ]);

    LoanRepayment::query()->create([
        'loan_id' => $loan->id,
        'amount' => 1_500,
        'paid_at' => now()->subDays(7),
    ]);
    LoanRepayment::query()->create([
        'loan_id' => $loan->id,
        'amount' => 2_000,
        'paid_at' => now()->subDays(2),
    ]);

    $snapshot = app(MemberDetailInsightsService::class)->snapshot($member->fresh());
    $lifetimeContributions = collect($snapshot['kpis'])->firstWhere('key', 'lifetime_contributions');
    $lifetimeRepaid = collect($snapshot['kpis'])->firstWhere('key', 'lifetime_repaid');
    $totalFundInflow = collect($snapshot['kpis'])->firstWhere('key', 'total_fund_inflow');

    expect($lifetimeContributions)->not->toBeNull()
        ->and($lifetimeContributions['sub'])->toBe(InsightFormatter::money(6_000.0))
        ->and($lifetimeRepaid)->not->toBeNull()
        ->and($lifetimeRepaid['sub'])->toBe(InsightFormatter::money(3_500.0))
        ->and($totalFundInflow)->not->toBeNull()
        ->and($totalFundInflow['sub'])->toBe(InsightFormatter::money(9_500.0));
});

test('member detail insights render negative total fund inflow in red with signed value', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 1000,
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    $member->cashAccount()->update(['balance' => -2_000]);

    $snapshot = app(MemberDetailInsightsService::class)->snapshot($member->fresh());
    $inflow = collect($snapshot['kpis'])->firstWhere('key', 'total_fund_inflow');

    expect($inflow)->not->toBeNull()
        ->and($inflow['value'])->toStartWith('-')
        ->and($inflow['accent'])->toBe('rose')
        ->and($inflow['value_class'])->toContain('text-rose-600');
});
