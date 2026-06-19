<?php

declare(strict_types=1);

use App\Filament\Member\Resources\MyCashOutRequests\MyCashOutRequestResource;
use App\Filament\Member\Resources\MyContributions\MyContributionResource;
use App\Filament\Member\Resources\MyDependents\MyDependentResource;
use App\Filament\Member\Support\MemberNavigation;
use App\Models\Central\Tenant;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use Filament\Facades\Filament;
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
        'member_number' => 'MEM-AR-NAV',
        'name' => 'Arabic Nav Member',
        'email' => 'ar-nav@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    $this->memberUser = User::create([
        'name' => $member->name,
        'email' => $member->email,
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
        'preferred_locale' => 'ar',
    ]);

    $member->update(['user_id' => $this->memberUser->id]);
});

test('member sidebar group labels render in arabic', function () {
    app()->setLocale('ar');
    session()->put('locale', 'ar');

    expect(MemberNavigation::groupLabel(MemberNavigation::GROUP_MY_ACCOUNTS))->toBe('حساباتي')
        ->and(MemberNavigation::groupLabel(MemberNavigation::GROUP_LOANS))->toBe('القروض')
        ->and(MemberNavigation::groupLabel(MemberNavigation::GROUP_HISTORY))->toBe('السجل')
        ->and(MemberNavigation::groupLabel(MemberNavigation::GROUP_SELF_SERVICE))->toBe('خدمة ذاتية');
});

test('member portal pages render arabic navigation labels when locale is ar', function () {
    app()->setLocale('ar');
    session()->put('locale', 'ar');

    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    $this->get('http://'.$this->domain.'/member')
        ->assertSuccessful()
        ->assertSee('نظرة عامة', false)
        ->assertSee('حساب النقد', false)
        ->assertSee('إجراءات سريعة', false);

    $this->get('http://'.$this->domain.'/member/cash-account')
        ->assertSuccessful()
        ->assertSee('حساب النقد', false);

    $this->get('http://'.$this->domain.'/member/settings')
        ->assertSuccessful()
        ->assertSee('الإعدادات', false);

    $this->get('http://'.$this->domain.'/member/help')
        ->assertSuccessful()
        ->assertSee('المساعدة والأسئلة الشائعة', false);

    $this->get('http://'.$this->domain.'/member/my-contributions')
        ->assertSuccessful()
        ->assertSee('مساهماتي', false);

    expect(MyContributionResource::getNavigationLabel())->toBe('مساهماتي')
        ->and(MyDependentResource::getNavigationLabel())->toBe('تابعيني')
        ->and(MyContributionResource::getPluralModelLabel())->toBe('مساهماتي')
        ->and(MyCashOutRequestResource::getPluralModelLabel())->toBe('عمليات السحب');
});
