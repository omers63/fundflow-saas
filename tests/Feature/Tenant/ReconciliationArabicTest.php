<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\ReconciliationOverviewPage;
use App\Models\Tenant\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;
use Tests\Support\AdminPortalTranslationCatalog;

uses(InitializesTenancy::class);

beforeEach(function (): void {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    App::setLocale('ar');
});

test('reconciliation page translation keys have arabic entries', function (): void {
    $keys = [
        'Reconciliation',
        'Fund status',
        'Open issues',
        'How it works',
        'How reconciliation works',
        'Current reconciliation settings',
        'Run check now',
        'Snapshots',
    ];

    /** @var array<string, string> $arabic */
    $arabic = json_decode((string) file_get_contents(base_path('lang/ar.json')), true, 512, JSON_THROW_ON_ERROR);

    foreach ($keys as $key) {
        expect($arabic)->toHaveKey($key)
            ->and(AdminPortalTranslationCatalog::looksArabic($arabic[$key]))->toBeTrue();
    }
});

test('reconciliation overview renders primary arabic headings', function (): void {
    $admin = User::create([
        'name' => 'Reconciliation Arabic Admin',
        'email' => 'reconciliation-ar@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(ReconciliationOverviewPage::class)
        ->assertSuccessful()
        ->assertSee(__('Reconciliation', locale: 'ar'), false)
        ->assertSee(__('Overview', locale: 'ar'), false);
});
