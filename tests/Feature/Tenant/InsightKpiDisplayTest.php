<?php

declare(strict_types=1);

use App\Support\Insights\InsightFormatter;
use App\Support\Insights\InsightKpi;
use Illuminate\Support\Facades\Blade;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

test('tenant kpi strip renders counts without money markup', function () {
    $html = Blade::render(<<<'BLADE'
        @include('filament.tenant.widgets.partials.insights-kpi-strip', [
            'kpis' => [
                [
                    'label' => 'Pending',
                    'value' => '12',
                    'sub' => 'Applications',
                    'accent' => 'amber',
                ],
            ],
        ])
    BLADE);

    expect($html)
        ->toContain('12')
        ->not->toContain('ff-member-amount')
        ->not->toContain('12.00');
});

test('tenant kpi strip renders monetary values when currency is set on the card', function () {
    $html = Blade::render(<<<'BLADE'
        @include('filament.tenant.widgets.partials.insights-kpi-strip', [
            'kpis' => [
                [
                    'label' => 'Outstanding',
                    'value' => 1500,
                    'currency' => 'SAR',
                    'value_is_amount' => true,
                    'sub' => 'Portfolio',
                    'accent' => 'violet',
                ],
            ],
        ])
    BLADE);

    expect($html)
        ->toContain('ff-member-amount')
        ->toContain('1,500.00');
});

test('member stat card renders count values without currency even when currency prop is passed', function () {
    $html = Blade::render(<<<'BLADE'
        <x-member::stat-card :label="__('Cycles missed')" :value="'3'" currency="SAR" />
    BLADE);

    expect($html)
        ->toContain('3')
        ->not->toContain('ff-member-amount')
        ->not->toContain('3.00');
});

test('member stat card renders monetary values through amount prop', function () {
    $html = Blade::render(<<<'BLADE'
        <x-member::stat-card :label="__('Fund balance')" :amount="2500.5" currency="SAR" />
    BLADE);

    expect($html)
        ->toContain('ff-member-amount')
        ->toContain('2,500.50');
});

test('insight kpi helpers distinguish money and count payloads', function () {
    expect(InsightKpi::moneyValue(1500, 'SAR'))
        ->toMatchArray([
            'value' => 1500.0,
            'currency' => 'SAR',
            'value_compact' => true,
            'value_is_amount' => true,
        ]);

    expect(InsightKpi::countValue(7))
        ->toMatchArray([
            'value' => '7',
            'value_is_amount' => false,
        ]);
});

test('compact amount strings stay plain text in kpi strip', function () {
    $html = Blade::render(<<<'BLADE'
        @include('filament.tenant.widgets.partials.insights-kpi-strip', [
            'kpis' => [
                [
                    'label' => 'Cash',
                    'value' => '12.5k',
                    'sub' => 'Full balance',
                    'accent' => 'sky',
                ],
            ],
        ])
    BLADE);

    expect($html)
        ->toContain('12.5k')
        ->not->toContain('ff-member-amount');
});

test('money sub labels still render when formatted with symbol', function () {
    $sub = InsightFormatter::money(1500);

    $html = Blade::render(<<<'BLADE'
        @include('filament.tenant.widgets.partials.insights-kpi-strip', [
            'kpis' => [
                [
                    'label' => 'Cash',
                    'value' => '12.5k',
                    'sub' => $sub,
                    'accent' => 'sky',
                ],
            ],
        ])
    BLADE, ['sub' => $sub]);

    expect($html)->toContain('ff-member-amount');
});

test('member kpi strip spreads columns evenly for four dependents stats', function () {
    $kpi = [
        'label' => 'Dependents',
        'value' => '2',
        'sub' => 'Funded',
        'accent' => 'teal',
        'icon' => 'heroicon-o-user-group',
    ];

    $html = Blade::render(<<<'BLADE'
        @include('filament.member.widgets.partials.insights-kpi-strip', [
            'kpis' => [$a, $b, $c, $d],
        ])
    BLADE, [
        'a' => $kpi,
        'b' => [...$kpi, 'label' => 'Set contributions'],
        'c' => [...$kpi, 'label' => 'Cash to transfer'],
        'd' => [...$kpi, 'label' => 'Contributions posted'],
    ]);

    expect($html)
        ->toContain('sm:grid-cols-4')
        ->not->toContain('xl:grid-cols-6');
});

test('member kpi strip uses six columns when six kpis are provided', function () {
    $kpi = [
        'label' => 'Cash',
        'value' => '1',
        'sub' => 'Balance',
        'accent' => 'sky',
        'icon' => 'heroicon-o-wallet',
    ];

    $html = Blade::render(<<<'BLADE'
        @include('filament.member.widgets.partials.insights-kpi-strip', [
            'kpis' => [$a, $b, $c, $d, $e, $f],
        ])
    BLADE, [
        'a' => $kpi,
        'b' => $kpi,
        'c' => $kpi,
        'd' => $kpi,
        'e' => $kpi,
        'f' => $kpi,
    ]);

    expect($html)->toContain('xl:grid-cols-6');
});
