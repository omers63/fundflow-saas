<?php

use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\MemberDetailInsightsService;
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
        ->and($snapshot['balances']['cash']['display'])->toBeString()->not->toBeEmpty()
        ->and($snapshot['balances']['fund']['display'])->toBeString()->not->toBeEmpty()
        ->and($snapshot['kpis'])->toHaveCount(6)
        ->and($snapshot['steps'])->not->toBeEmpty()
        ->and($snapshot['cycle']['period_label'])->toBeString()
        ->and($snapshot['trend'])->toHaveCount(6)
        ->and($snapshot['sparkline'])->toHaveCount(8)
        ->and($snapshot['relation_summaries'])->toHaveCount(4);
});
