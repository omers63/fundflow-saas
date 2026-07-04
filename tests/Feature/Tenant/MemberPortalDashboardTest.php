<?php

declare(strict_types=1);

use App\Models\Central\Tenant;
use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\MemberPortalInsightsService;
use App\Support\BusinessDaySettings;
use Carbon\Carbon;
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

    Account::query()->delete();
    Contribution::query()->delete();
    Loan::query()->delete();
    Member::query()->delete();
    User::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);

    $accounting = app(AccountingService::class);

    $this->memberUser = User::create([
        'name' => 'Dashboard Member',
        'email' => 'dashboard@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
        'preferred_locale' => 'en',
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-DASH01',
        'name' => 'Dashboard Member',
        'email' => 'dashboard@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $accounting->createMemberAccounts($this->member);

    Setting::set('general', 'currency', 'SAR');
    BusinessDaySettings::saveFromForm(null);

    Contribution::create([
        'member_id' => $this->member->id,
        'period' => now()->subMonth()->startOfMonth(),
        'amount' => 1000,
        'status' => 'posted',
        'posted_at' => now(),
    ]);
});

test('member dashboard renders redesigned zones with quick actions', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    $this->get('http://'.$this->domain.'/member')
        ->assertSuccessful()
        ->assertSee('ff-member-dashboard-overview', false)
        ->assertSee('ff-member-dashboard-account-grid', false)
        ->assertSee('ff-member-cash-hero', false)
        ->assertSee(__('Cash account'), false)
        ->assertSee(__('Fund account'), false)
        ->assertSee(__('Loan eligibility'), false)
        ->assertSee(__('Quick actions'), false)
        ->assertSee(__('Forecasts'), false)
        ->assertSee('ff-member-greeting', false)
        ->assertSee('Dashboard Member', false)
        ->assertSee('ff-member-greeting__spotlights', false)
        ->assertDontSee('ff-member-journey', false);
});

test('member dashboard shows active loan panel when member has active loan', function () {
    $loan = Loan::query()->create([
        'member_id' => $this->member->id,
        'amount' => 5000,
        'amount_requested' => 5000,
        'amount_approved' => 5000,
        'amount_disbursed' => 5000,
        'interest_rate' => 0,
        'term_months' => 10,
        'monthly_repayment' => 500,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => now()->subMonth(),
        'approved_at' => now()->subMonth(),
        'disbursed_at' => now()->subMonth(),
    ]);

    LoanInstallment::query()->create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 500,
        'due_date' => now()->addDays(7),
        'status' => 'pending',
    ]);

    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    $this->get('http://'.$this->domain.'/member')
        ->assertSuccessful()
        ->assertSee(__('Active loan — #:id', ['id' => $loan->id]), false)
        ->assertSee(__('Next EMI due'), false)
        ->assertDontSee(__('Loan eligibility'), false);
});

test('member dashboard shows loan repayment notice instead of contribution not posted during active loan', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-18 10:00:00'));
    BusinessDaySettings::saveFromForm('2026-06-18');

    $loan = Loan::query()->create([
        'member_id' => $this->member->id,
        'amount' => 5000,
        'amount_requested' => 5000,
        'amount_approved' => 5000,
        'amount_disbursed' => 5000,
        'interest_rate' => 0,
        'term_months' => 10,
        'monthly_repayment' => 500,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => now()->subMonth(),
        'approved_at' => now()->subMonth(),
        'disbursed_at' => now()->subMonth(),
    ]);

    LoanInstallment::query()->create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 500,
        'due_date' => Carbon::parse('2026-08-01'),
        'status' => 'pending',
    ]);

    $cycles = app(ContributionCycleService::class);
    [$curMonth, $curYear] = $cycles->currentOpenPeriod();

    Filament::setCurrentPanel('member');
    app()->setLocale('en');

    $snapshot = app(MemberPortalInsightsService::class)->snapshot($this->member->fresh());

    expect($snapshot['notice']['title'] ?? null)->toBe(__('Active loan in progress'))
        ->and($snapshot['cycle']['under_loan_repayment'] ?? false)->toBeTrue()
        ->and($snapshot['loan_panel'])->not->toBeNull();

    $this->actingAs($this->memberUser, 'tenant');

    $this->get('http://'.$this->domain.'/member')
        ->assertSuccessful()
        ->assertSee('ff-member-greeting', false)
        ->assertSee(__('Active loan in progress'), false)
        ->assertDontSee('Contribution not posted', false);

    Carbon::setTestNow();
    BusinessDaySettings::saveFromForm(null);
});

test('member dashboard recent activity table lists cash transactions', function () {
    $cash = $this->member->cashAccount;

    Transaction::create([
        'account_id' => $cash->id,
        'member_id' => $this->member->id,
        'type' => 'credit',
        'amount' => 250,
        'balance_after' => 250,
        'description' => 'Test deposit credit',
        'transacted_at' => Carbon::parse('2026-06-10'),
    ]);

    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    $this->get('http://'.$this->domain.'/member')
        ->assertSuccessful()
        ->assertSee(__('Recent transactions'), false)
        ->assertSee('Test deposit credit', false)
        ->assertSee('CR', false);
});

test('member dashboard my insights stat cards place riyal symbol before amount in arabic', function () {
    $this->memberUser->update(['preferred_locale' => 'ar']);
    session()->put('locale', 'ar');

    $this->member->fundAccount()->update(['balance' => 5000]);

    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser->fresh(), 'tenant');

    $html = $this->get('http://'.$this->domain.'/member')->getContent();

    expect($html)->toContain('ff-member-amount')
        ->and(mb_strpos($html, 'ff-sar-symbol__img'))->not->toBeFalse()
        ->and(mb_strpos($html, 'ff-sar-symbol__img'))->toBeLessThan(mb_strpos($html, 'ff-member-amount__digits'));
});

test('member dashboard renders arabic labels with western digits for amounts', function () {
    $this->memberUser->update(['preferred_locale' => 'ar']);
    session()->put('locale', 'ar');

    $this->member->cashAccount()->update(['balance' => 1500]);
    $this->member->fundAccount()->update(['balance' => 1500]);

    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser->fresh(), 'tenant');

    $response = $this->get('http://'.$this->domain.'/member');

    $response->assertSuccessful()
        ->assertSee('ff-member-dashboard-overview', false)
        ->assertSee('حساب الصند', false)
        ->assertSee('1,500.00', false)
        ->assertSee('ff-sar-symbol__img', false)
        ->assertDontSee('١٬٥٠٠', false);
});
