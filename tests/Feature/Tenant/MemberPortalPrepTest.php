<?php

declare(strict_types=1);

use App\Filament\Member\Pages\BusinessDayTestingPage;
use App\Models\Central\Tenant;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Setting::query()->delete();

    $tenant = Tenant::find('testing');
    $this->domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $this->domain)->exists()) {
        $tenant->domains()->create(['domain' => $this->domain]);
    }

    $member = Member::create([
        'member_number' => 'MEM-'.uniqid(),
        'name' => 'Portal Prep Member',
        'email' => 'portal-prep@fund.test',
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
});

test('business day testing page is hidden from member navigation but remains accessible', function () {
    expect(BusinessDayTestingPage::shouldRegisterNavigation())->toBeFalse();
});

test('members can access business day testing page', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    expect(BusinessDayTestingPage::canAccess())->toBeTrue();

    $this->get('http://'.$this->domain.'/member/business-calendar-testing')
        ->assertSuccessful();
});

test('business day testing page is registered on member panel', function () {
    Filament::setCurrentPanel('member');

    expect(filament()->getPanel('member')->getPages())
        ->toContain(BusinessDayTestingPage::class);
});

test('business day testing page title is translated in english or arabic', function (string $locale) {
    $this->memberUser->update(['preferred_locale' => $locale]);
    session()->put('locale', $locale);

    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser->fresh(), 'tenant');

    $this->get('http://'.$this->domain.'/member/business-calendar-testing')
        ->assertSuccessful()
        ->assertSee(__('Business calendar (testing)'), false);
})->with([
    'english' => ['en'],
    'arabic' => ['ar'],
]);

test('business day footer banner on member panel links to testing page', function () {
    Setting::set('general', 'business_day', '2026-03-15');

    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    $this->get('http://'.$this->domain.'/member')
        ->assertSuccessful()
        ->assertSee('ff-status-footer-banner--business-day', false)
        ->assertSee(__('Change on Business calendar (testing).'), false)
        ->assertSee('/member/business-calendar-testing', false);
});
