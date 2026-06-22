<?php

declare(strict_types=1);

use App\Models\Central\Tenant;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Illuminate\Support\Facades\Blade;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    $tenant = Tenant::find('testing');
    $this->domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $this->domain)->exists()) {
        $tenant->domains()->create(['domain' => $this->domain]);
    }

    $member = Member::create([
        'member_number' => 'MEM-'.uniqid(),
        'name' => 'Design System Member',
        'email' => 'design-system@fund.test',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(6),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    $this->memberUser = User::create([
        'name' => $member->name,
        'email' => $member->email,
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
        'preferred_locale' => 'en',
    ]);

    $member->update(['user_id' => $this->memberUser->id]);

    $this->member = $member->fresh();
});

test('member theme imports prototype chrome stylesheet', function () {
    $theme = file_get_contents(resource_path('css/filament/member/theme.css'));

    expect($theme)->toContain("@import './member-portal-chrome.css'");

    expect(file_exists(resource_path('css/filament/member/member-portal-chrome.css')))->toBeTrue();

    $chrome = file_get_contents(resource_path('css/filament/member/member-portal-chrome.css'));

    expect($chrome)
        ->toContain('--ff-primary: #534ab7')
        ->toContain('.fi-body.fi-panel-member')
        ->toMatch('/\.fi-body\.fi-panel-member\s+\.fi-sidebar/');
});

test('member panel primary color is prototype purple and tenant panel is unchanged', function () {
    $memberPrimary = filament()->getPanel('member')->getColors()['primary'];
    $tenantPrimary = filament()->getPanel('tenant')->getColors()['primary'];

    expect($memberPrimary)->toBe(Color::hex('#534AB7'))
        ->and($tenantPrimary)->toBe(Color::Sky)
        ->and(filament()->getPanel('member')->getSidebarWidth())->toBe('14.5rem')
        ->and(filament()->getPanel('member')->getCollapsedSidebarWidth())->toBe('4rem');
});

test('member portal page renders with member panel scope class', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    $this->get('http://'.$this->domain.'/member')
        ->assertSuccessful()
        ->assertSee('fi-panel-member', false);
});

test('vite build manifest includes member theme entry', function () {
    $manifestPath = public_path('build/manifest.json');

    expect(file_exists($manifestPath))->toBeTrue();

    $manifest = json_decode((string) file_get_contents($manifestPath), true);

    expect($manifest)->toHaveKey('resources/css/filament/member/theme.css');
});

test('member portal chrome css defines prototype design tokens', function () {
    $chrome = file_get_contents(resource_path('css/filament/member/member-portal-chrome.css'));

    expect($chrome)
        ->toContain('--ff-page-bg: #f9fafb')
        ->toContain('--ff-panel-radius: 14px')
        ->toContain('color-scheme: light');
});

test('member sidebar profile block renders on dashboard', function () {
    $this->member->update([
        'member_number' => 'MEM-1047',
        'joined_at' => Carbon::parse('2018-03-01'),
    ]);

    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    $this->get('http://'.$this->domain.'/member')
        ->assertSuccessful()
        ->assertSee('ff-member-sidebar-profile', false)
        ->assertSee('ff-member-sidebar-profile__meta', false)
        ->assertSee('x-show="$store.sidebar.isOpen"', false)
        ->assertSee('Design System Member', false)
        ->assertSee('MEM-1047', false)
        ->assertSee(__('Active'), false);
});

test('member sidebar profile subtitle uses western digits in arabic', function () {
    $this->memberUser->update(['preferred_locale' => 'ar']);
    session()->put('locale', 'ar');

    $this->member->update([
        'member_number' => 'MEM-1047',
        'joined_at' => Carbon::parse('2018-03-01'),
    ]);

    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser->fresh(), 'tenant');

    $response = $this->get('http://'.$this->domain.'/member');

    $response->assertSuccessful()
        ->assertSee('ff-member-sidebar-profile', false)
        ->assertSee('2018', false)
        ->assertDontSee('٢٠١٨', false);
});

test('member theme imports component stylesheet', function () {
    $theme = file_get_contents(resource_path('css/filament/member/theme.css'));

    expect($theme)->toContain("@import './member-portal-components.css'");
});

test('x-member blade components render prototype class hooks', function () {
    $html = Blade::render(<<<'BLADE'
        <x-member::panel :title="__('Cash account')">
            <x-member::amount :value="3240" currency="SAR" />
        </x-member::panel>
        <x-member::notice tone="amber" :title="__('Reminder')">{{ __('Payment due') }}</x-member::notice>
        <x-member::chip variant="green">{{ __('Active') }}</x-member::chip>
        <x-member::stat-card :label="__('Balance')" :amount="1234567.89" currency="SAR" />
        <x-member::panel-actions>
            <span class="fi-btn fi-btn-size-sm">{{ __('Action') }}</span>
        </x-member::panel-actions>
        <x-member::quick-action href="#" icon="💰" :title="__('Deposit')" />
        <x-member::progress-bar :percent="65" />
        <x-member::detail-grid :items="[['label' => __('Period'), 'value' => 'Jan 2026']]" />
        <div class="ff-member-record-modal"><div class="ff-member-record-modal__hero"></div></div>
    BLADE);

    expect($html)
        ->toContain('ff-member-panel')
        ->toContain('ff-member-amount')
        ->toContain('ff-sar-symbol')
        ->toContain('ff-member-notice--amber')
        ->toContain('ff-member-chip--green')
        ->toContain('ff-member-stat-card')
        ->toContain('ff-member-panel-actions')
        ->toContain('min-w-0')
        ->toContain('title="')
        ->toContain('ff-member-quick-action')
        ->toContain('ff-member-progress')
        ->toContain('ff-member-detail-grid')
        ->toContain('ff-member-record-modal');
});

test('member portal component css clips stat card overflow', function () {
    $components = file_get_contents(resource_path('css/filament/member/member-portal-components.css'));
    $currency = file_get_contents(resource_path('css/filament/currency-display.css'));
    $tooltips = file_get_contents(resource_path('css/filament/stat-widget-tooltips.css'));

    expect($components)
        ->toContain('.ff-member-stat-card__value')
        ->toContain('text-overflow: ellipsis')
        ->toContain('ff-member-fund-account-stats')
        ->toContain('ff-member-dashboard-insights-stats')
        ->toContain('ff-member-contribution-option')
        ->toContain('ff-member-panel-actions');

    expect($currency)
        ->toContain('.ff-member-stat-card .ff-member-amount')
        ->toContain('.ff-member-greeting__balance .ff-member-amount')
        ->toContain('.ff-member-cash-stat .ff-member-amount');

    expect($tooltips)->toContain('.ff-app-insights-kpi-strip .grid > *');
});

test('member portal component css defines record modal layout hooks', function () {
    $components = file_get_contents(resource_path('css/filament/member/member-portal-components.css'));

    expect($components)
        ->toContain('ff-member-record-modal-window')
        ->toContain('ff-member-record-modal__hero')
        ->toContain('ff-member-detail-grid--3col')
        ->toContain('ff-member-dashboard-account-grid')
        ->toContain('ff-member-cash-hero')
        ->toContain('.fi-sidebar:not(.fi-sidebar-open) .ff-member-sidebar-profile__meta')
        ->toContain('width: 1.5rem');
});

test('x-member.amount renders negative fund balances in red without signed prop', function () {
    $html = Blade::render(
        '<x-member::amount :value="-1500" currency="SAR" class="ff-member-dashboard-balance__value ff-member-dashboard-balance__value--fund" />',
    );

    expect($html)
        ->toContain('ff-member-amount--danger')
        ->toContain('1,500.00');
});

test('x-member.amount uses western digits and places riyal symbol before amount in arabic', function () {
    app()->setLocale('ar');

    $html = Blade::render(
        '<div dir="rtl"><x-member::amount :value="3240" currency="SAR" /></div>',
    );

    expect($html)
        ->toContain('ff-sar-symbol__img')
        ->toContain('3,240.00')
        ->not->toMatch('/[٠-٩]/u');

    expect(mb_strpos($html, 'ff-sar-symbol__img'))->toBeLessThan(mb_strpos($html, '3,240.00'));
});
