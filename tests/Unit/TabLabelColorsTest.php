<?php

use App\Filament\Support\TabLabelColors;

test('tab label colors resolve from tab labels', function () {
    expect(TabLabelColors::forLabel('Cash'))->toBe('info')
        ->and(TabLabelColors::forLabel('Fund'))->toBe('success')
        ->and(TabLabelColors::forLabel('Accounts'))->toBe('info');
});

test('tab label colors resolve known list tab keys', function () {
    expect(TabLabelColors::forKey('cash::tab'))->toBe('info')
        ->and(TabLabelColors::forKey('fund::tab'))->toBe('success')
        ->and(TabLabelColors::forKey('loans::tab'))->toBe('warning')
        ->and(TabLabelColors::forKey('all::tab'))->toBe('gray');
});

test('tab label colors resolve relation manager relationships', function () {
    expect(TabLabelColors::forKey('accounts'))->toBe('info')
        ->and(TabLabelColors::forKey('contributions'))->toBe('success')
        ->and(TabLabelColors::forKey('directMessages'))->toBe('info');
});

test('unknown tab keys receive a stable palette color', function () {
    $first = TabLabelColors::forKey('custom-widget::tab');
    $second = TabLabelColors::forKey('custom-widget::tab');

    expect($first)->toBe($second)
        ->and($first)->toBeIn(['primary', 'info', 'success', 'warning', 'danger', 'gray']);
});
