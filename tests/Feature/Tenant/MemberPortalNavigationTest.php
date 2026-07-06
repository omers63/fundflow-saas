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
use App\Filament\Member\Widgets\MyMemberRequestsTableWidget;
use App\Filament\Member\Widgets\MySupportRequestsTableWidget;
use App\Models\Central\Tenant;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use Filament\Facades\Filament;
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

test('member panel registers messages and account pages', function () {
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

test('apply for loan page is registered but hidden from sidebar navigation', function () {
    Filament::setCurrentPanel('member');

    expect(filament()->getPanel('member')->getPages())->toContain(ApplyForLoan::class)
        ->and(ApplyForLoan::shouldRegisterNavigation())->toBeFalse();
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
        ->assertRedirect('/member/messages?tab=requests');

    $this->get('http://'.$this->domain.'/member/my-messages')
        ->assertRedirect('/member/messages?tab=messages');

    $this->get('http://' . $this->domain . '/member/help?tab=messages')
        ->assertRedirect('/member/messages?tab=messages');
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

test('communications help requests tab renders support and membership sections', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    Livewire::test(CommunicationsPage::class)
        ->set('activeTab', 'requests')
        ->set('requestsSection', 'support')
        ->assertSet('activeTab', 'requests')
        ->assertSet('requestsSection', 'support')
        ->assertSuccessful();

    Livewire::test(CommunicationsPage::class)
        ->set('activeTab', 'requests')
        ->call('setRequestsSection', 'membership')
        ->assertSet('requestsSection', 'membership')
        ->assertSuccessful();

    Livewire::test(MySupportRequestsTableWidget::class)
        ->assertTableHeaderActionsExistInOrder(['submit_request'])
        ->assertSuccessful();

    Livewire::test(MyMemberRequestsTableWidget::class)
        ->assertTableActionExists('requestFreezeMembership');
});

test('deposit create route remains available when list nav is hidden', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    $this->get('http://'.$this->domain.'/member/'.MyFundPostingResource::getSlug().'/create')
        ->assertSuccessful();
});
