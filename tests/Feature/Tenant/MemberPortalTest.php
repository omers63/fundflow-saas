<?php

use App\Filament\Member\Pages\ApplyForLoan;
use App\Filament\Member\Pages\LoanCalculatorPage;
use App\Filament\Member\Pages\MemberDashboard;
use App\Filament\Member\Resources\MyAccounts\MyAccountResource;
use App\Filament\Member\Resources\MyContributions\MyContributionResource;
use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Filament\Member\Resources\MyMessages\MyMessageResource;
use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\DirectMessage;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Support\PublicPageSettings;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Contribution::query()->delete();
    Loan::query()->delete();
    Member::query()->delete();
    User::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);

    $accounting = app(AccountingService::class);

    $this->adminUser = User::create([
        'name' => 'Fund Admin',
        'email' => 'admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->memberUserA = User::create([
        'name' => 'Alice Member',
        'email' => 'alice@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->memberA = Member::create([
        'user_id' => $this->memberUserA->id,
        'member_number' => 'MEM-A001',
        'name' => 'Alice Member',
        'email' => 'alice@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subMonths(14),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($this->memberA);

    $this->memberUserB = User::create([
        'name' => 'Bob Member',
        'email' => 'bob@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->memberB = Member::create([
        'user_id' => $this->memberUserB->id,
        'member_number' => 'MEM-B001',
        'name' => 'Bob Member',
        'email' => 'bob@fund.test',
        'monthly_contribution_amount' => 2000,
        'joined_at' => now()->subMonths(6),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($this->memberB);

    Contribution::create(['member_id' => $this->memberA->id, 'period' => now()->subMonth()->startOfMonth(), 'amount' => 1000, 'status' => 'posted', 'posted_at' => now()]);
    Contribution::create(['member_id' => $this->memberA->id, 'period' => now()->subMonths(2)->startOfMonth(), 'amount' => 1000, 'status' => 'posted', 'posted_at' => now()]);
    Contribution::create(['member_id' => $this->memberB->id, 'period' => now()->subMonth()->startOfMonth(), 'amount' => 2000, 'status' => 'posted', 'posted_at' => now()]);

    Loan::create([
        'member_id' => $this->memberA->id,
        'amount' => 5000,
        'interest_rate' => 5,
        'term_months' => 12,
        'monthly_repayment' => 437.50,
        'total_repaid' => 0,
        'status' => 'disbursed',
        'applied_at' => now()->subWeek(),
        'approved_at' => now()->subDays(5),
        'disbursed_at' => now()->subDays(3),
    ]);
});

test('admin can access admin panel', function () {
    $panel = filament()->getPanel('tenant');
    expect($this->adminUser->canAccessPanel($panel))->toBeTrue();
});

test('member cannot access admin panel', function () {
    $panel = filament()->getPanel('tenant');
    expect($this->memberUserA->canAccessPanel($panel))->toBeFalse();
});

test('member can access member portal', function () {
    $panel = filament()->getPanel('member');
    expect($this->memberUserA->canAccessPanel($panel))->toBeTrue();
});

test('user without member profile cannot access member portal', function () {
    $orphanUser = User::create([
        'name' => 'Orphan',
        'email' => 'orphan@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $panel = filament()->getPanel('member');
    expect($orphanUser->canAccessPanel($panel))->toBeFalse();
});

test('account resource scopes to authenticated member', function () {
    auth('tenant')->login($this->memberUserA);

    $query = MyAccountResource::getEloquentQuery();
    $accounts = $query->get();

    expect($accounts)->toHaveCount(2);
    expect($accounts->pluck('member_id')->unique()->values()->all())->toBe([$this->memberA->id]);
    expect($accounts->where('is_master', true))->toHaveCount(0);
});

test('contribution resource scopes to authenticated member', function () {
    auth('tenant')->login($this->memberUserA);

    $query = MyContributionResource::getEloquentQuery();
    $contributions = $query->get();

    expect($contributions)->toHaveCount(2);
    expect($contributions->pluck('member_id')->unique()->values()->all())->toBe([$this->memberA->id]);
});

test('loan resource scopes to authenticated member', function () {
    auth('tenant')->login($this->memberUserA);

    $query = MyLoanResource::getEloquentQuery();
    $loans = $query->get();

    expect($loans)->toHaveCount(1);
    expect($loans->first()->member_id)->toBe($this->memberA->id);
});

test('member B sees no loans because they have none', function () {
    auth('tenant')->login($this->memberUserB);

    $query = MyLoanResource::getEloquentQuery();
    expect($query->count())->toBe(0);
});

test('member B sees only their own contributions', function () {
    auth('tenant')->login($this->memberUserB);

    $query = MyContributionResource::getEloquentQuery();
    $contributions = $query->get();

    expect($contributions)->toHaveCount(1);
    expect($contributions->first()->amount)->toBe('2000.00');
});

test('portal resources cannot be created', function () {
    expect(MyAccountResource::canCreate())->toBeFalse();
    expect(MyContributionResource::canCreate())->toBeFalse();
    expect(MyLoanResource::canCreate())->toBeFalse();
});

test('user model has member relationship', function () {
    expect($this->memberUserA->member)->not->toBeNull();
    expect($this->memberUserA->member->id)->toBe($this->memberA->id);
    expect($this->adminUser->member)->toBeNull();
});

test('member panel uses custom dashboard page', function () {
    $panel = filament()->getPanel('member');

    expect($panel->getPages())->toContain(MemberDashboard::class);
});

test('message resource scopes to member admin conversations', function () {
    auth('tenant')->login($this->memberUserA);

    DirectMessage::create([
        'from_user_id' => $this->adminUser->id,
        'to_user_id' => $this->memberUserA->id,
        'subject' => 'Notice',
        'body' => 'Please review your statement.',
    ]);

    DirectMessage::create([
        'from_user_id' => $this->memberUserB->id,
        'to_user_id' => $this->adminUser->id,
        'subject' => 'Other member',
        'body' => 'Should not appear',
    ]);

    expect(MyMessageResource::getEloquentQuery()->count())->toBe(1);
});

test('apply for loan page is registered on member panel', function () {
    expect(ApplyForLoan::getSlug())->toBe('apply-for-loan');
});

test('loan calculator page renders for member', function () {
    $this->actingAs($this->memberUserA, 'tenant');

    Livewire::test(LoanCalculatorPage::class)
        ->assertSuccessful()
        ->assertSee(__('Loan calculator'))
        ->assertSee(__('Estimate your loan repayment'))
        ->set('loanAmount', 10000)
        ->assertSet('loanAmount', 10000.0);
});

test('member panel has database notifications enabled', function () {
    expect(filament()->getPanel('member')->hasDatabaseNotifications())->toBeTrue()
        ->and(filament()->getPanel('member')->hasLazyLoadedDatabaseNotifications())->toBeFalse()
        ->and(filament()->getPanel('member')->getDatabaseNotificationsPollingInterval())->toBe('10s')
        ->and(filament()->getPanel('member')->hasBroadcasting())->toBeTrue()
        ->and(config('filament.broadcasting.echo.broadcaster'))->toBe('reverb');
});

test('member portal topbar shows fund name beside logo', function () {
    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'fund_name_en' => 'Al Noor Fund',
        'fund_name_ar' => 'صندوق النور',
    ]);

    app()->setLocale('en');

    Filament::setCurrentPanel('member');

    $html = FilamentView::renderHook(PanelsRenderHook::TOPBAR_LOGO_AFTER)->toHtml();

    expect($html)
        ->toContain('ff-member-topbar-fund-name')
        ->toContain('Al Noor Fund');
});
