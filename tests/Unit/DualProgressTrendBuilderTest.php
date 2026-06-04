<?php

declare(strict_types=1);

use App\Models\Tenant\Member;
use App\Services\ContributionCycleService;
use App\Support\Insights\DualProgressTrendBuilder;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

test('member collection trend uses expected count of one for liable periods', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 800,
        'joined_at' => now()->subYear(),
    ]);

    $trend = DualProgressTrendBuilder::sixMonthMemberCollectionTrend(
        $member,
        app(ContributionCycleService::class),
    );

    expect($trend)->toHaveCount(6)
        ->and(collect($trend)->every(fn (array $row): bool => array_key_exists('collection_rate', $row)))->toBeTrue();
});

test('workflow trend maps success and decided rates', function () {
    $row = DualProgressTrendBuilder::buildWorkflowMonthRow('Jan', 10, 8, 9);

    expect($row['collection_rate'])->toBe(80)
        ->and($row['amount_collection_rate'])->toBe(90)
        ->and($row['tone'])->toBe('warning');
});
