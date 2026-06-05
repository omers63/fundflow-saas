<?php

declare(strict_types=1);

use App\Http\Livewire\LanguageSwitchComponent;
use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Livewire\Livewire;

test('filament language switch component switches locale via switchLocale action', function () {
    session()->put('locale', 'ar');

    Livewire::test(LanguageSwitchComponent::class)
        ->call('switchLocale', 'en')
        ->assertRedirect();

    expect(session('locale'))->toBe('en');
});

test('language switch dropdown uses dedicated class and switchLocale wire action', function () {
    $html = view('language-switch::switch', [
        'languageSwitch' => LanguageSwitch::make(),
        'locales' => ['ar', 'en'],
        'isCircular' => true,
        'isFlagsOnly' => false,
        'hasFlags' => true,
        'placement' => 'bottom-end',
        'maxHeight' => null,
    ])->render();

    expect($html)
        ->toContain('language-switch-dropdown')
        ->toContain('wire:click.stop="switchLocale')
        ->not->toContain('fi-user-menu');
});
