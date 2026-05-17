<?php

use App\Support\Lang;
use Tests\TestCase;

uses(TestCase::class);

it('title-cases translated ui keys via Lang::ui', function (): void {
    expect(Lang::ui('member cash balance'))->toBe('Member Cash Balance');
});

it('title-cases already translated text via Lang::uiText', function (): void {
    expect(Lang::uiText('3 loans awaiting action'))->toBe('3 Loans Awaiting Action');
});

it('formats label and sub keys in labeled rows', function (): void {
    expect(Lang::formatLabeledRow([
        'label' => 'open period',
        'sub' => 'no active members',
        'value' => '42',
    ]))->toBe([
        'label' => 'Open Period',
        'sub' => 'No Active Members',
        'value' => '42',
    ]);
});

it('title-cases translated option labels', function (): void {
    expect(Lang::transOptions([
        'a' => 'pending review',
        'b' => 'approved',
    ]))->toBe([
        'a' => 'Pending Review',
        'b' => 'Approved',
    ]);
});
