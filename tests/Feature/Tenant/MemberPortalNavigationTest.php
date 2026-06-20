<?php

declare(strict_types=1);

use App\Filament\Member\Pages\ApplyForLoan;
use App\Filament\Member\Pages\CashAccountPage;
use App\Filament\Member\Pages\CommunicationsPage;
use App\Filament\Member\Pages\FundAccountPage;
use App\Filament\Member\Pages\LoanCalculatorPage;
use App\Filament\Member\Pages\MemberActivityPage;
use App\Filament\Member\Pages\MemberSettingsPage;
use App\Filament\Member\Resources\MyFundPostings\MyFundPostingResource;
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
        'member_number' => 'MEM-NAV01',
        'name' => 'Navigation Member',
        'email' => 'nav@fund.test',
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
    ]);

    $member->update(['user_id' => $this->memberUser->id]);
    $this->member = $member->fresh();
});

test('member panel registers help and account pages', function () {
    Filament::setCurrentPanel('member');

    expect(filament()->getPanel('member')->getPages())
        ->toContain(CommunicationsPage::class)
        ->toContain(CashAccountPage::class)
        ->toContain(FundAccountPage::class)
        ->toContain(MemberActivityPage::class)
        ->toContain(MemberSettingsPage::class)
        ->toContain(LoanCalculatorPage::class)
        ->toContain(ApplyForLoan::class);
});

test('deposits list is available in sidebar again', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    expect(MyFundPostingResource::shouldRegisterNavigation())->toBeTrue();

    $this->get('http://'.$this->domain.'/member/my-fund-postings')
        ->assertSuccessful();
});

test('legacy member paths redirect to redesigned destinations', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    $this->get('http://'.$this->domain.'/member/my-accounts')
        ->assertRedirect('/member/cash-account');

    $this->get('http://'.$this->domain.'/member/support')
        ->assertRedirect('/member/help?tab=requests');

    $this->get('http://'.$this->domain.'/member/my-messages')
        ->assertRedirect('/member/help?tab=messages');
});

test('cash and fund account pages render redesigned layouts', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    $this->get('http://'.$this->domain.'/member/cash-account')
        ->assertSuccessful()
        ->assertSee('ff-member-cash-account', false)
        ->assertSee(__('Submit a deposit'), false)
        ->assertSee(__('Cash transactions'), false);

    $this->get('http://'.$this->domain.'/member/fund-account')
        ->assertSuccessful()
        ->assertSee('ff-member-fund-account', false)
        ->assertSee('ff-member-fund-hero', false)
        ->assertSee(__('Fund transactions'), false);
});

test('communications help page renders tab shell', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    $this->get('http://'.$this->domain.'/member/help')
        ->assertSuccessful()
        ->assertSee('ff-member-communications', false)
        ->assertSee(__('Inbox'), false);

    $this->get('http://'.$this->domain.'/member/help?tab=requests')
        ->assertSuccessful()
        ->assertSee(__('Requests'), false);

    $this->get('http://'.$this->domain.'/member/settings')
        ->assertSuccessful()
        ->assertSee('ff-member-settings', false);
});

test('deposit create route remains available when list nav is hidden', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    $this->get('http://'.$this->domain.'/member/'.MyFundPostingResource::getSlug().'/create')
        ->assertSuccessful();
});
