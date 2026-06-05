<?php

declare(strict_types=1);

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Services\ContributionCycleService;
use App\Support\Insights\DualProgressTrendBuilder;
use Carbon\Carbon;
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

test('member collection trend compares posted amounts to cycle due from contribution rows', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 1000,
        'joined_at' => Carbon::parse('2026-01-01'),
    ]);

    $cycles = app(ContributionCycleService::class);
    [$openMonth, $openYear] = $cycles->currentOpenPeriod();
    $previousCursor = Carbon::create($openYear, $openMonth, 1)->subMonthNoOverflow();
    $previousPeriod = Contribution::periodDate((int) $previousCursor->month, (int) $previousCursor->year);

    Contribution::factory()->for($member)->posted()->create([
        'period' => $previousPeriod,
        'amount' => 500,
        'amount_due' => 500,
    ]);

    $bucket = collect(DualProgressTrendBuilder::sixMonthMemberCollectionTrend($member, $cycles))
        ->firstWhere('label', $previousCursor->locale(app()->getLocale())->translatedFormat('M'));

    expect($bucket)->not->toBeNull()
        ->and($bucket['posted_amount'])->toBe(500.0)
        ->and($bucket['expected_amount'])->toBe(500.0)
        ->and($bucket['amount_collection_rate'])->toBe(100)
        ->and($bucket['amount_tone'])->toBe('success')
        ->and($bucket['subtitle'])->toContain('500');

    Carbon::setTestNow();
});

test('member collection trend uses current monthly amount only for open cycle without a row', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 1000,
        'joined_at' => Carbon::parse('2026-05-01'),
    ]);

    $cycles = app(ContributionCycleService::class);
    [$openMonth, $openYear] = $cycles->currentOpenPeriod();
    $openPeriod = Contribution::periodDate($openMonth, $openYear);

    $bucket = collect(DualProgressTrendBuilder::sixMonthMemberCollectionTrend($member, $cycles))
        ->firstWhere('label', Carbon::create($openYear, $openMonth, 1)->locale(app()->getLocale())->translatedFormat('M'));

    expect($bucket)->not->toBeNull()
        ->and($bucket['posted'])->toBe(0)
        ->and($bucket['expected_amount'])->toBe(1000.0)
        ->and($bucket['subtitle'])->toContain('1k');

    Carbon::setTestNow();
});

test('workflow trend maps success and decided rates', function () {
    $row = DualProgressTrendBuilder::buildWorkflowMonthRow('Jan', 10, 8, 9);

    expect($row['collection_rate'])->toBe(80)
        ->and($row['amount_collection_rate'])->toBe(90)
        ->and($row['tone'])->toBe('warning');
});
