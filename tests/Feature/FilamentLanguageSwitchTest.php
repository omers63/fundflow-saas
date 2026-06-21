<?php

declare(strict_types=1);

use App\Http\Livewire\LanguageSwitchComponent;
use App\Http\Livewire\TenantTopbarLanguageSwitch;
use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Livewire\Livewire;

test('filament language switch component switches locale via switchLocale action', function () {
    session()->put('locale', 'ar');

    Livewire::test(LanguageSwitchComponent::class)
        ->call('switchLocale', 'en')
        ->assertRedirect();

    expect(session('locale'))->toBe('en');
});

test('tenant topbar language switch shows locale label and sized dropdown options', function () {
    app()->setLocale('en');

    Livewire::test(TenantTopbarLanguageSwitch::class)
        ->assertSee('English')
        ->assertSeeHtml('ff-portal-topbar-language-switch__option')
        ->assertSeeHtml('ff-portal-topbar-chip');
});

test('tenant topbar language switch stays visible on mobile viewports', function () {
    $contents = file_get_contents(resource_path('views/filament/tenant/partials/topbar-admin-shortcuts.blade.php'));

    expect($contents)
        ->toContain('tenant-topbar-language-switch')
        ->toContain('ff-portal-topbar-locale')
        ->toContain('ff-portal-topbar-shortcuts me-1 flex shrink-0')
        ->not->toContain('ff-portal-topbar-shortcuts me-1 hidden');
});

test('member topbar shortcuts mirror admin layout with cycle messages and language switch', function () {
    $contents = file_get_contents(resource_path('views/filament/member/partials/topbar-member-shortcuts.blade.php'));

    expect($contents)
        ->toContain('MyContributionResource::getUrl')
        ->toContain('MyMessageResource::getUrl')
        ->toContain('tenant-topbar-language-switch')
        ->toContain('ff-portal-topbar-locale')
        ->toContain('ff-portal-topbar-shortcuts me-1 flex shrink-0')
        ->toContain('hidden shrink-0 items-center gap-2 sm:flex')
        ->not->toContain('ff-portal-topbar-shortcuts me-1 hidden');
});

test('public language switch uses chip trigger with mobile flag-only label', function () {
    $contents = file_get_contents(resource_path('views/components/language-switcher.blade.php'));

    expect($contents)
        ->toContain('language-switch-trigger__label hidden sm:inline')
        ->not->toContain('language-switch-trigger--labeled');
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
