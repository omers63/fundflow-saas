<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\AuditSystemPage;
use App\Filament\Tenant\Pages\Dashboard;
use App\Filament\Tenant\Pages\DisbursementsPage;
use App\Filament\Tenant\Pages\LoanEmiCollectionCalendarPage;
use App\Filament\Tenant\Pages\MessagesInboxPage;
use App\Filament\Tenant\Pages\ReconciliationOverviewPage;
use App\Filament\Tenant\Pages\ReportsPage;
use App\Filament\Tenant\Pages\Settings;
use App\Filament\Tenant\Support\TenantSidebarRegistry;
use App\Models\Central\Tenant;
use App\Models\Tenant\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    $tenant = Tenant::find('testing');
    $this->domain = 'testing.localhost';

    if (!$tenant->domains()->where('domain', $this->domain)->exists()) {
        $tenant->domains()->create(['domain' => $this->domain]);
    }

    App::setLocale('en');
    Filament::setCurrentPanel('tenant');
});

/**
 * @var list<array{path: string, label: string}>
 */
const ADMIN_PORTAL_HTTP_SMOKE_ROUTES = [
    ['path' => '/admin', 'label' => 'dashboard'],
    ['path' => '/admin/members', 'label' => 'members'],
    ['path' => '/admin/loans/loans', 'label' => 'loans'],
    ['path' => '/admin/loans/loans/queue', 'label' => 'loan queue'],
    ['path' => '/admin/contributions', 'label' => 'collections'],
    ['path' => '/admin/disbursements', 'label' => 'disbursements'],
    ['path' => '/admin/bank-accounts', 'label' => 'bank clearing'],
    ['path' => '/admin/reconciliation', 'label' => 'reconciliation'],
    ['path' => '/admin/reports', 'label' => 'reports'],
    ['path' => '/admin/audit-system', 'label' => 'audit system'],
    ['path' => '/admin/settings', 'label' => 'settings'],
    ['path' => '/admin/messages', 'label' => 'messages'],
    ['path' => '/admin/loans/emi-collection-calendar', 'label' => 'emi calendar'],
];

function createTenantAdmin(string $suffix): User
{
    return User::create([
        'name' => 'Phase Twelve Admin ' . $suffix,
        'email' => 'phase-twelve-' . $suffix . '@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);
}

test('tenant admin portal theme assets are built and scoped', function () {
    $manifestPath = public_path('build/manifest.json');

    expect(file_exists($manifestPath))->toBeTrue();

    $manifest = json_decode((string) file_get_contents($manifestPath), true);

    expect($manifest)->toHaveKey('resources/css/filament/tenant/theme.css');

    $theme = file_get_contents(resource_path('css/filament/tenant/theme.css'));

    expect($theme)
        ->toContain('--ff-primary:')
        ->toContain('.fi-body.fi-panel-tenant')
        ->toContain('ff-tenant-topbar-chip')
        ->toContain("html[dir='rtl'] .fi-body.fi-panel-tenant");
});

test('consolidated admin routes respond successfully over http in english', function (array $route) {
    $admin = createTenantAdmin($route['label']);

    App::setLocale('en');

    $this->actingAs($admin, 'tenant')
        ->get('http://' . $this->domain . $route['path'])
        ->assertSuccessful()
        ->assertSee('fi-panel-tenant', false);
})->with(array_map(fn(array $route): array => [$route], ADMIN_PORTAL_HTTP_SMOKE_ROUTES));

test('consolidated admin routes respond successfully over http in arabic', function (array $route) {
    $admin = createTenantAdmin('ar-' . $route['label']);

    App::setLocale('ar');

    $this->actingAs($admin, 'tenant')
        ->get('http://' . $this->domain . $route['path'])
        ->assertSuccessful()
        ->assertSee('dir="rtl"', false)
        ->assertSee('fi-panel-tenant', false);
})->with(array_map(fn(array $route): array => [$route], ADMIN_PORTAL_HTTP_SMOKE_ROUTES));

test('admin dashboard http response includes redesign chrome', function () {
    $admin = createTenantAdmin('dashboard-chrome');

    $this->actingAs($admin, 'tenant');

    $this->get('http://' . $this->domain . '/admin')
        ->assertSuccessful()
        ->assertSee('ff-tenant-topbar-chip', false)
        ->assertSee(__('Fund pool health'), false)
        ->assertSee(__('Loan pipeline'), false);
});

test('settings page renders external pill navigation in english and arabic', function (string $locale) {
    App::setLocale($locale);

    $admin = createTenantAdmin('settings-' . $locale);

    Livewire::actingAs($admin, 'tenant')
        ->test(Settings::class)
        ->assertSuccessful()
        ->assertSee(__('General', locale: $locale))
        ->assertSee('ff-tenant-tab-pills', false)
        ->assertSee('fi-page-settings', false);
})->with(['en', 'ar']);

test('audit system workspace tabs render over livewire in english and arabic', function (string $locale, string $tab) {
    App::setLocale($locale);

    $admin = createTenantAdmin("audit-{$locale}-{$tab}");

    Livewire::actingAs($admin, 'tenant')
        ->test(AuditSystemPage::class, ['sideTab' => $tab])
        ->assertSuccessful();
})->with([
            'en',
            'ar',
        ])->with([
            'audit',
            'notifications',
            'jobs',
            'fiscal',
        ]);

test('tenant admin redesign livewire pages render without error', function (string $component) {
    $admin = createTenantAdmin(md5($component));

    Livewire::actingAs($admin, 'tenant')
        ->test($component)
        ->assertSuccessful();
})->with([
            Dashboard::class,
            DisbursementsPage::class,
            ReportsPage::class,
            ReconciliationOverviewPage::class,
            AuditSystemPage::class,
            Settings::class,
            MessagesInboxPage::class,
            LoanEmiCollectionCalendarPage::class,
        ]);

test('consolidated sidebar registry matches live navigation labels in english', function () {
    App::setLocale('en');

    $admin = createTenantAdmin('sidebar-registry');

    $this->actingAs($admin, 'tenant');

    $labels = collect(Filament::getNavigation())
        ->flatMap(fn($group) => collect($group->getItems())->map(fn($item) => (string) $item->getLabel()))
        ->values()
        ->all();

    expect($labels)->toHaveCount(10);

    foreach (TenantSidebarRegistry::consolidatedNavigationLabels() as $label) {
        expect($labels)->toContain($label);
    }
});
