<?php

declare(strict_types=1);

use App\Filament\Support\MoneyDisplay;
use App\Filament\Tenant\Pages\AuditSystemPage;
use App\Filament\Tenant\Pages\Dashboard;
use App\Filament\Tenant\Pages\DisbursementsPage;
use App\Filament\Tenant\Pages\LoanEmiCollectionCalendarPage;
use App\Filament\Tenant\Pages\MessagesInboxPage;
use App\Filament\Tenant\Pages\ReconciliationOverviewPage;
use App\Filament\Tenant\Pages\ReportsPage;
use App\Filament\Tenant\Pages\Settings;
use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Widgets\TenantDashboardWidget;
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

    if (! $tenant->domains()->where('domain', $this->domain)->exists()) {
        $tenant->domains()->create(['domain' => $this->domain]);
    }

    Filament::setCurrentPanel('tenant');
});

test('tenant admin dashboard renders rtl layout and arabic dashboard copy', function () {
    App::setLocale('ar');

    $admin = User::create([
        'name' => 'Coverage Admin',
        'email' => 'coverage-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
        'preferred_locale' => 'ar',
    ]);

    $this->actingAs($admin, 'tenant');

    $this->get('http://'.$this->domain.'/admin')
        ->assertSuccessful()
        ->assertSee('dir="rtl"', false)
        ->assertSee(__('Fund pool health', locale: 'ar'), false)
        ->assertSee(__('Loan pipeline', locale: 'ar'), false);
});

test('tenant admin money display uses western digits in arabic locale', function () {
    App::setLocale('ar');

    expect(MoneyDisplay::format(3240, 'SAR'))
        ->toContain('3,240.00')
        ->and(MoneyDisplay::amount(3240))->toBe('3,240.00');
});

test('consolidated admin portal pages render in arabic locale', function (string $component) {
    App::setLocale('ar');

    $admin = User::create([
        'name' => 'Arabic Page Admin '.md5($component),
        'email' => 'arabic-page-'.md5($component).'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

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
    LoanEmiCollectionCalendarPage::class,
    MessagesInboxPage::class,
]);

test('consolidated admin portal resources render list pages in arabic locale', function (string $resource) {
    App::setLocale('ar');

    $admin = User::create([
        'name' => 'Arabic Resource Admin '.md5($resource),
        'email' => 'arabic-resource-'.md5($resource).'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test($resource::getPages()['index']->getPage())
        ->assertSuccessful();
})->with([
    MemberResource::class,
    ContributionResource::class,
    LoanResource::class,
    BankAccountsResource::class,
]);

test('tenant dashboard widget renders in arabic locale', function () {
    App::setLocale('ar');

    $admin = User::create([
        'name' => 'Dashboard Widget Admin',
        'email' => 'dashboard-widget@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(TenantDashboardWidget::class)
        ->assertSuccessful()
        ->assertSee(__('Recent activity', locale: 'ar'));
});

test('reconciliation snapshot pdf template declares rtl when locale is arabic', function () {
    App::setLocale('ar');

    $html = view('pdf.reconciliation-snapshot', [
        'snapshot' => (object) [
            'id' => 1,
            'mode' => 'daily',
            'as_of' => now(),
            'period_start' => null,
            'period_end' => null,
            'is_passing' => true,
            'critical_issues' => 0,
            'warnings' => 0,
            'report' => [
                'checks' => [],
                'pipeline' => [
                    'bank_unposted_count' => 0,
                    'bank_unposted_amount' => 0,
                    'sms_unposted_count' => 0,
                    'sms_unposted_amount' => 0,
                ],
            ],
        ],
    ])->render();

    expect($html)
        ->toContain('dir="rtl"')
        ->toContain('lang="ar"')
        ->toContain(__('Financial reconciliation report', locale: 'ar'));
});
