<?php

declare(strict_types=1);

use App\Support\Insights\ValueChart;

it('builds donut shares that sum to one hundred when total is positive', function (): void {
    $chart = ValueChart::donut('Mix', [
        ['key' => 'a', 'label' => 'A', 'value' => 25],
        ['key' => 'b', 'label' => 'B', 'value' => 75],
    ], 'Sub', '100');

    expect($chart['type'])->toBe('donut')
        ->and($chart['total'])->toBe(100.0)
        ->and($chart['segments'][0]['share'] + $chart['segments'][1]['share'])->toBe(100.0)
        ->and($chart['segments'][1]['offset'])->toBe(25.0);
});

it('builds stack, bars, funnel, aging, and levels payloads', function (): void {
    $stack = ValueChart::stack('Liquidity', [
        ['key' => 'cash', 'label' => 'Cash', 'value' => 40],
        ['key' => 'fund', 'label' => 'Fund', 'value' => 60],
    ]);

    $bars = ValueChart::bars('Top', [
        ['key' => 'm1', 'label' => 'Member', 'value' => 200],
        ['key' => 'm2', 'label' => 'Other', 'value' => 100],
    ], asMoney: true);

    $funnel = ValueChart::funnel('Pipe', [
        ['key' => 'intake', 'label' => 'Intake', 'value' => 10],
        ['key' => 'queued', 'label' => 'Queued', 'value' => 5],
    ]);

    $aging = ValueChart::aging('Age', [
        ['key' => '1-30', 'label' => '1-30', 'count' => 2, 'amount' => 50],
        ['key' => '90+', 'label' => '90+', 'count' => 1, 'amount' => 100],
    ]);

    $levels = ValueChart::levels('Runway', [
        ['key' => 'cash', 'label' => 'Cash', 'value' => 80],
        ['key' => 'cash_outs', 'label' => 'Outs', 'value' => 40],
    ]);

    expect($stack['type'])->toBe('stack')
        ->and($stack['segments'][0]['share'])->toBe(40.0)
        ->and($bars['type'])->toBe('bars')
        ->and($bars['as_money'])->toBeTrue()
        ->and($bars['rows'][0]['width'])->toBe(100.0)
        ->and($funnel['steps'][1]['width'])->toBe(50.0)
        ->and($aging['buckets'][1]['width'])->toBe(100.0)
        ->and($levels['points'][1]['height'])->toBe(50.0);
});

it('forces value chart visual plane to left-to-right', function (): void {
    $view = file_get_contents(resource_path('views/filament/partials/insights/value-chart.blade.php'));

    expect($view)->toContain('dir="ltr"')
        ->toContain('ff-value-chart');
});

it('wires all eight value chart folds into tenant views', function (): void {
    $paths = [
        resource_path('views/filament/tenant/widgets/contributions/collect.blade.php') => 'value_chart_collection',
        resource_path('views/filament/tenant/widgets/loans/portfolio.blade.php') => 'value_chart_portfolio',
        resource_path('views/filament/tenant/widgets/member-insights.blade.php') => 'value_chart_concentration',
        resource_path('views/filament/tenant/widgets/master-accounts-insights.blade.php') => 'value_chart_liquidity',
        resource_path('views/filament/tenant/widgets/tenant-dashboard.blade.php') => 'value_charts',
        resource_path('views/filament/tenant/pages/delinquency-workspace.blade.php') => 'value_chart_aging',
        resource_path('views/filament/tenant/pages/loan-queue-workbench.blade.php') => 'value_chart_pipeline',
        resource_path('views/filament/tenant/pages/reconciliation.blade.php') => 'value_chart_recon',
    ];

    foreach ($paths as $path => $section) {
        expect(file_get_contents($path))
            ->toContain($section)
            ->toContain('ValueChartsService')
            ->toContain('isSectionUnfolded');
    }
});
