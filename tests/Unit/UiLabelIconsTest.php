<?php

use App\Filament\Support\UiLabelIcons;
use Filament\Support\Icons\Heroicon;
use Tests\TestCase;

uses(TestCase::class);

test('ui label icons resolve from labels and column names', function () {
    expect(UiLabelIcons::forLabel('Cash'))->toBe(Heroicon::OutlinedBanknotes)
        ->and(UiLabelIcons::forColumnName('member.name'))->toBe(Heroicon::OutlinedUser)
        ->and(UiLabelIcons::forKey('contributions'))->toBe(Heroicon::OutlinedChartBar);
});

test('labeled html wraps text with an icon span', function () {
    $html = UiLabelIcons::labeledHtml('Amount', UiLabelIcons::forKey('amount'))->toHtml();

    expect($html)
        ->toContain('fi-ff-label-with-icon')
        ->toContain('Amount');
});
