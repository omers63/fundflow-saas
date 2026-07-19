<?php

declare(strict_types=1);

use App\Filament\Tenant\Support\TenantSidebarRegistry;
use App\Models\Tenant\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Facades\App;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    App::setLocale('en');
});

/**
 * @return list<string>
 */
function tenantSidebarLabels(): array
{
    return collect(Filament::getNavigation())
        ->flatMap(function (NavigationGroup $group): array {
            return collect($group->getItems())
                ->filter(fn (NavigationItem $item): bool => $item->isVisible())
                ->map(fn (NavigationItem $item): string => (string) $item->getLabel())
                ->all();
        })
        ->values()
        ->all();
}

test('tenant admin sidebar shows only consolidated navigation entries', function () {
    $admin = User::create([
        'name' => 'Sidebar Admin',
        'email' => 'sidebar-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($admin, 'tenant');

    $labels = tenantSidebarLabels();

    expect($labels)->toHaveCount(17)
        ->and($labels)->toBe([
            'Dashboard',
            'Members',
            'Applications',
            'Requests',
            'Loans',
            'Loan Queue',
            'Contributions',
            'Disbursements',
            'Deposits',
            'Cash Outs',
            'Bank Clearing',
            'Sms Clearing',
            'Transactions',
            'Reconciliation',
            'Reports',
            'Audit & System',
            'Settings',
        ]);
});

test('consolidated sidebar hides moved operational and finance items for admin', function () {
    $admin = User::create([
        'name' => 'Sidebar Admin Two',
        'email' => 'sidebar-admin-two@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($admin, 'tenant');

    $labels = tenantSidebarLabels();

    foreach (TenantSidebarRegistry::hiddenFromSidebar() as $class) {
        expect($labels)->not->toContain($class::getNavigationLabel());
    }
});
