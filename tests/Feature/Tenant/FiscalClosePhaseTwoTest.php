<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\FiscalClose;
use App\Models\Tenant\FiscalCloseMemberSnapshot;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\FiscalClose\FiscalClosePeriodResolver;
use App\Services\FiscalClose\FiscalCloseService;
use App\Support\FiscalSettings;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Setting::query()->delete();
    FiscalClose::query()->delete();
    FiscalCloseMemberSnapshot::query()->delete();
    Account::query()->delete();
    Member::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);

    FiscalSettings::saveFromForm([
        'fiscal_year_start_month' => 1,
        'fiscal_year_start_day' => 1,
        'purge_policy' => FiscalSettings::PURGE_ARCHIVE_THEN_DELETE,
        'current_fiscal_year_label' => 'FY2026',
    ]);
});

function createTenantAdmin(): User
{
    return User::create([
        'name' => 'Close Admin',
        'email' => 'close-admin-'.uniqid('', true).'@fund.test',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);
}

function createBalancedMember(float $cash = 100, float $fund = 200): Member
{
    $member = Member::factory()->create([
        'opening_cash_balance' => $cash,
        'opening_fund_balance' => $fund,
        'opening_balances_posted_at' => now(),
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2026-01-01'),
    ]);

    $cashAccount = Account::create([
        'member_id' => $member->id,
        'type' => 'cash',
        'name' => $member->name.' - Cash',
        'balance' => $cash,
        'is_master' => false,
    ]);

    Account::create([
        'member_id' => $member->id,
        'type' => 'fund',
        'name' => $member->name.' - Fund',
        'balance' => $fund,
        'is_master' => false,
    ]);

    Account::where('is_master', true)->where('type', 'cash')->update(['balance' => $cash]);
    Account::where('is_master', true)->where('type', 'fund')->update(['balance' => $fund]);

    $member->setRelation('cashAccount', $cashAccount);

    return $member->fresh(['cashAccount', 'fundAccount']);
}

test('prepare snapshot creates close record and member snapshots when readiness passes', function () {
    createBalancedMember();
    $admin = createTenantAdmin();
    $periodEnd = Carbon::parse('2026-12-31');

    $close = app(FiscalCloseService::class)->prepareSnapshot('FY2026', $periodEnd, $admin);

    expect($close->status)->toBe(FiscalClose::STATUS_PENDING_APPROVAL)
        ->and($close->member_count)->toBe(1)
        ->and($close->checksum)->not->toBeEmpty()
        ->and($close->memberSnapshots)->toHaveCount(1)
        ->and((float) $close->memberSnapshots->first()->cash_balance)->toBe(100.0);
});

test('roll forward updates opening balances and books closed through', function () {
    $member = createBalancedMember(250, 500);
    $admin = createTenantAdmin();
    $periodEnd = Carbon::parse('2026-12-31');

    $close = app(FiscalCloseService::class)->prepareSnapshot('FY2026', $periodEnd, $admin);
    $close = app(FiscalCloseService::class)->approveAndRollForward($close, $admin);

    $member->refresh();

    expect($close->status)->toBe(FiscalClose::STATUS_ROLLED_FORWARD)
        ->and((float) $member->opening_cash_balance)->toBe(250.0)
        ->and((float) $member->opening_fund_balance)->toBe(500.0)
        ->and($member->opening_balances_posted_at)->not->toBeNull()
        ->and(FiscalSettings::booksClosedThrough()?->toDateString())->toBe('2026-12-31')
        ->and(FiscalSettings::currentFiscalYearLabel())->toBe('FY2027');
});

test('backdated postings are rejected after books closed through is set', function () {
    $member = createBalancedMember();
    $admin = createTenantAdmin();
    $periodEnd = Carbon::parse('2026-12-31');

    $close = app(FiscalCloseService::class)->prepareSnapshot('FY2026', $periodEnd, $admin);
    app(FiscalCloseService::class)->approveAndRollForward($close, $admin);

    $cash = $member->fresh()->cashAccount;
    expect($cash)->not->toBeNull();

    app(FiscalClosePeriodResolver::class)->assertNotClosed(Carbon::parse('2027-01-15'));

    expect(fn () => app(AccountingService::class)->credit(
        $cash,
        10,
        'Backdated test',
        null,
        Carbon::parse('2026-12-15'),
        $member->id,
    ))->toThrow(InvalidArgumentException::class);
});

test('cannot prepare snapshot twice for the same fiscal year after roll forward', function () {
    createBalancedMember();
    $admin = createTenantAdmin();
    $periodEnd = Carbon::parse('2026-12-31');

    $close = app(FiscalCloseService::class)->prepareSnapshot('FY2026', $periodEnd, $admin);
    app(FiscalCloseService::class)->approveAndRollForward($close, $admin);

    expect(fn () => app(FiscalCloseService::class)->prepareSnapshot('FY2026', $periodEnd, $admin))
        ->toThrow(InvalidArgumentException::class);
});
