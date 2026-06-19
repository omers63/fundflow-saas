<?php

declare(strict_types=1);

use App\Filament\Member\Pages\FundAccountPage;
use App\Filament\Member\Support\MemberNavigation;
use App\Models\Central\Tenant;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
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
        'member_number' => 'MEM-FUND01',
        'name' => 'Fund Page Member',
        'email' => 'fundpage@fund.test',
        'monthly_contribution_amount' => 1500,
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

    Setting::set('general', 'currency', 'SAR');
});

test('fund account page shows hero stats and fund ledger', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    $this->member->fundAccount()->update(['balance' => 12000]);

    Transaction::create([
        'account_id' => $this->member->fundAccount->id,
        'member_id' => $this->member->id,
        'type' => 'credit',
        'amount' => 12000,
        'balance_after' => 12000,
        'description' => 'Fund contribution',
        'transacted_at' => now(),
    ]);

    [$openMonth, $openYear] = app(ContributionCycleService::class)->currentOpenPeriod();

    Contribution::create([
        'member_id' => $this->member->id,
        'period' => Contribution::periodDate($openMonth, $openYear),
        'amount' => 1500,
        'status' => 'posted',
        'posted_at' => now(),
    ]);

    $this->get('http://'.$this->domain.'/member/fund-account')
        ->assertSuccessful()
        ->assertSee('ff-member-fund-account', false)
        ->assertSee('ff-member-fund-hero', false)
        ->assertSee(__('Accumulated fund balance'), false)
        ->assertSee(__('Monthly contribution'), false)
        ->assertSee(__('Loan cap'), false)
        ->assertSee(__('Posted'), false)
        ->assertSee(__('Fund transactions'), false)
        ->assertSee('Fund contribution', false);
});

test('fund account page is registered in member navigation', function () {
    Filament::setCurrentPanel('member');

    expect(FundAccountPage::getNavigationGroup())->toBe(MemberNavigation::GROUP_MY_ACCOUNTS)
        ->and(FundAccountPage::getNavigationSort())->toBe(MemberNavigation::SORT_FUND_ACCOUNT);
});
