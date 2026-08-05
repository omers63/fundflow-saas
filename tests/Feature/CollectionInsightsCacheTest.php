<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Support\CollectionInsightsCache;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    CollectionInsightsCache::bumpAll();
    Cache::flush();
    CollectionInsightsCache::bumpAll();
});

test('collection insights cache returns memoized payload until generation bump', function () {
    $calls = 0;

    $first = CollectionInsightsCache::remember(
        CollectionInsightsCache::DOMAIN_CONTRIBUTIONS,
        'test:payload',
        function () use (&$calls): array {
            $calls++;

            return ['value' => 1];
        },
    );

    $second = CollectionInsightsCache::remember(
        CollectionInsightsCache::DOMAIN_CONTRIBUTIONS,
        'test:payload',
        function () use (&$calls): array {
            $calls++;

            return ['value' => 2];
        },
    );

    expect($first)->toBe(['value' => 1])
        ->and($second)->toBe(['value' => 1])
        ->and($calls)->toBe(1);

    CollectionInsightsCache::bump(CollectionInsightsCache::DOMAIN_CONTRIBUTIONS);

    $third = CollectionInsightsCache::remember(
        CollectionInsightsCache::DOMAIN_CONTRIBUTIONS,
        'test:payload',
        function () use (&$calls): array {
            $calls++;

            return ['value' => 3];
        },
    );

    expect($third)->toBe(['value' => 3])
        ->and($calls)->toBe(2);
});

test('collection insights cache isolates payloads by locale', function () {
    app()->setLocale('ar');

    CollectionInsightsCache::remember(
        CollectionInsightsCache::DOMAIN_CONTRIBUTIONS,
        'locale:payload',
        fn (): array => ['label' => 'عربي'],
    );

    app()->setLocale('en');

    $english = CollectionInsightsCache::remember(
        CollectionInsightsCache::DOMAIN_CONTRIBUTIONS,
        'locale:payload',
        fn (): array => ['label' => 'English'],
    );

    app()->setLocale('ar');

    $arabicAgain = CollectionInsightsCache::remember(
        CollectionInsightsCache::DOMAIN_CONTRIBUTIONS,
        'locale:payload',
        fn (): array => ['label' => 'should-not-run'],
    );

    expect($english)->toBe(['label' => 'English'])
        ->and($arabicAgain)->toBe(['label' => 'عربي']);
});

test('contribution browse flush keeps insights cache generation', function () {
    $generationBefore = Cache::get('contribution_insights:generation', 1);

    ContributionResource::flushPeriodCountCaches(bumpInsights: false);

    expect(Cache::get('contribution_insights:generation', 1))->toBe($generationBefore);
});

test('loan browse flush keeps insights cache generation', function () {
    $generationBefore = Cache::get('loan_emi_insights:generation', 1);

    LoanResource::flushCycleCollectionCountCaches(bumpInsights: false);

    expect(Cache::get('loan_emi_insights:generation', 1))->toBe($generationBefore);
});
