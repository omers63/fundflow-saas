<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\AuditSystemPage;
use App\Filament\Tenant\Pages\Dashboard;
use App\Filament\Tenant\Pages\ReportsPage;
use App\Filament\Tenant\Pages\Settings;
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

test('admin portal core page translation keys have arabic entries', function (): void {
    $keys = [
        'Configure currency, contribution rules, loan policies, reconciliation, and tenant-wide defaults.',
        'Fund audit log',
        'Custom report builder',
        'Audit trail, notification delivery, scheduled jobs, maintenance, migration, and year-end close.',
        'Standard exports and shortcuts to portfolio, collection, and reconciliation views.',
    ];

    /** @var array<string, string> $arabic */
    $arabic = json_decode((string) file_get_contents(base_path('lang/ar.json')), true, 512, JSON_THROW_ON_ERROR);

    foreach ($keys as $key) {
        expect($arabic)->toHaveKey($key)
            ->and(AdminPortalTranslationCatalog::looksArabic($arabic[$key]))->toBeTrue();
    }
});

test('settings reports audit and dashboard render primary arabic headings', function (): void {
    $admin = User::create([
        'name' => 'Arabic Core Pages Admin',
        'email' => 'arabic-core-pages@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(Settings::class)
        ->assertSuccessful()
        ->assertSee(__('Organisation settings', locale: 'ar'), false);

    Livewire::actingAs($admin, 'tenant')
        ->test(ReportsPage::class)
        ->assertSuccessful()
        ->assertSee(__('Custom report builder', locale: 'ar'), false);

    Livewire::actingAs($admin, 'tenant')
        ->test(AuditSystemPage::class)
        ->assertSuccessful()
        ->assertSee(__('Audit log', locale: 'ar'), false)
        ->assertSee(__('Fund audit log', locale: 'ar'), false);

    Livewire::actingAs($admin, 'tenant')
        ->test(Dashboard::class)
        ->assertSuccessful()
        ->assertSee(__('Fund pool health', locale: 'ar'), false);
});
