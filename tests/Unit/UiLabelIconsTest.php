<?php

use App\Filament\Support\UiLabelIcons;
use Filament\Support\Icons\Heroicon;

test('ui label icons resolve from labels and column names', function () {
    expect(UiLabelIcons::forLabel('Cash'))->toBe(Heroicon::OutlinedBanknotes)
        ->and(UiLabelIcons::forColumnName('member.name'))->toBe(Heroicon::OutlinedUser)
        ->and(UiLabelIcons::forKey('contributions'))->toBe(Heroicon::OutlinedChartBar);
});

test('ui label icons resolve master account types', function () {
    expect(UiLabelIcons::forKey('bank'))->toBe(Heroicon::OutlinedBuildingLibrary)
        ->and(UiLabelIcons::forKey('expense'))->toBe(Heroicon::OutlinedReceiptPercent)
        ->and(UiLabelIcons::forKey('invest'))->toBe(Heroicon::OutlinedChartBar);
});

test('ui label icons resolve tab keys and labels', function () {
    expect(UiLabelIcons::forTab('portfolio', __('Portfolio')))->toBe(Heroicon::OutlinedBriefcase)
        ->and(UiLabelIcons::forTab('pending', __('Pending')))->toBe(Heroicon::OutlinedClock)
        ->and(UiLabelIcons::forTab(null, __('Unknown tab')))->toBe(Heroicon::OutlinedViewColumns);
});

test('tab pill html always includes an icon', function () {
    $html = UiLabelIcons::tabPillHtml('Custom tab', 'unknown-key')->toHtml();

    expect($html)->toContain('fi-ff-label-with-icon');
});

test('labeled html wraps text with an icon span', function () {
    $html = UiLabelIcons::labeledHtml('Amount', UiLabelIcons::forKey('amount'))->toHtml();

    expect($html)
        ->toContain('fi-ff-label-with-icon')
        ->toContain('Amount');
});

test('unknown column labels fall back to the default table icon', function () {
    $html = UiLabelIcons::labeledHtml('Custom metric', UiLabelIcons::forKey('default'))->toHtml();

    expect($html)->toContain('fi-ff-label-with-icon');
});
