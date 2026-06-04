<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\FiscalClose;
use App\Models\Tenant\FiscalCloseMemberSnapshot;
use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\FiscalClose\FiscalClosePurgeService;
use App\Services\FiscalClose\FiscalCloseService;
use App\Services\MasterAccountInvariantService;
use App\Services\MemberInvariantService;
use App\Support\FiscalSettings;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Setting::query()->delete();
    FiscalClose::query()->delete();
    FiscalCloseMemberSnapshot::query()->delete();
    Transaction::query()->delete();
    BankTransaction::query()->delete();
    BankStatement::query()->delete();
    ReconciliationException::query()->delete();
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

function createTenantAdminForPurge(): User
{
    return User::create([
        'name' => 'Purge Admin',
        'email' => 'purge-admin-'.uniqid('', true).'@fund.test',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);
}

function createBalancedMemberForPurge(float $cash = 100, float $fund = 200): Member
{
    $member = Member::factory()->create([
        'opening_cash_balance' => $cash,
        'opening_fund_balance' => $fund,
        'opening_balances_posted_at' => now(),
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2026-01-01'),
    ]);

    Account::create([
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

    return $member->fresh(['cashAccount', 'fundAccount']);
}

function closeFiscalYearForPurge(?Carbon $periodEnd = null, ?Member $member = null): FiscalClose
{
    $periodEnd ??= Carbon::parse('2026-12-31');
    $member ??= createBalancedMemberForPurge();
    $admin = createTenantAdminForPurge();
    $close = app(FiscalCloseService::class)->prepareSnapshot('FY2026', $periodEnd, $admin);

    return app(FiscalCloseService::class)->approveAndRollForward($close, $admin);
}

test('tier a purge completes after roll forward and preserves balances', function () {
    FiscalSettings::saveFromForm([
        'fiscal_year_start_month' => 1,
        'fiscal_year_start_day' => 1,
        'purge_policy' => FiscalSettings::PURGE_RETAIN_7Y,
        'current_fiscal_year_label' => 'FY2026',
    ]);

    $member = createBalancedMemberForPurge();
    $close = closeFiscalYearForPurge(null, $member);

    $summary = app(FiscalCloseService::class)->executeTierAPurge($close->fresh());

    expect($close->fresh()->status)->toBe(FiscalClose::STATUS_PURGED)
        ->and($summary)->toHaveKeys(['transactions', 'bank_transactions', 'reconciliation_exceptions'])
        ->and(app(MasterAccountInvariantService::class)->check()['balanced'])->toBeTrue()
        ->and(app(MemberInvariantService::class)->check($member->fresh())['balanced'])->toBeTrue()
        ->and((float) $member->fresh()->getCashBalance())->toBe(100.0)
        ->and((float) $member->fresh()->getFundBalance())->toBe(200.0);
});

test('purgeTransactionsThrough deletes only ledger rows through period end', function () {
    $member = createBalancedMemberForPurge();
    $cash = $member->cashAccount;
    $masterCash = Account::masterCash();

    Transaction::create([
        'account_id' => $cash->id,
        'member_id' => $member->id,
        'type' => 'credit',
        'amount' => 50,
        'balance_after' => 150,
        'description' => 'Old member cash',
        'transacted_at' => Carbon::parse('2026-06-01'),
    ]);

    Transaction::create([
        'account_id' => $masterCash->id,
        'type' => 'credit',
        'amount' => 50,
        'balance_after' => 150,
        'description' => 'Old master cash',
        'transacted_at' => Carbon::parse('2026-06-01'),
    ]);

    Transaction::create([
        'account_id' => $cash->id,
        'member_id' => $member->id,
        'type' => 'credit',
        'amount' => 25,
        'balance_after' => 175,
        'description' => 'New year member cash',
        'transacted_at' => Carbon::parse('2027-01-10'),
    ]);

    $deleted = app(FiscalClosePurgeService::class)->purgeTransactionsThrough(Carbon::parse('2026-12-31')->endOfDay());

    expect($deleted)->toBe(2)
        ->and(Transaction::query()->count())->toBe(1);
});

test('tier a purge deletes cleared bank lines through period end but keeps uncleared lines', function () {
    FiscalSettings::saveFromForm([
        'fiscal_year_start_month' => 1,
        'fiscal_year_start_day' => 1,
        'purge_policy' => FiscalSettings::PURGE_RETAIN_7Y,
        'current_fiscal_year_label' => 'FY2026',
    ]);

    $close = closeFiscalYearForPurge();

    $statement = BankStatement::create([
        'filename' => 'test.csv',
        'imported_at' => now(),
        'row_count' => 2,
    ]);

    BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => '2026-05-01',
        'description' => 'Cleared old',
        'amount' => 50,
        'transaction_type' => 'credit',
        'status' => 'imported',
        'hash' => 'cleared-'.uniqid('', true),
        'is_cleared' => true,
        'cleared_at' => now(),
    ]);

    BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => '2026-05-01',
        'description' => 'Uncleared old',
        'amount' => 75,
        'transaction_type' => 'credit',
        'status' => 'imported',
        'hash' => 'uncleared-'.uniqid('', true),
        'is_cleared' => false,
    ]);

    $summary = app(FiscalCloseService::class)->executeTierAPurge($close->fresh());

    expect($summary['bank_transactions'])->toBe(1)
        ->and(BankTransaction::query()->count())->toBe(1)
        ->and(BankTransaction::query()->first()->description)->toBe('Uncleared old');
});

test('tier a purge cannot run before roll forward', function () {
    createBalancedMemberForPurge();
    $admin = createTenantAdminForPurge();
    $close = app(FiscalCloseService::class)->prepareSnapshot('FY2026', Carbon::parse('2026-12-31'), $admin);

    expect(fn () => app(FiscalCloseService::class)->executeTierAPurge($close))
        ->toThrow(InvalidArgumentException::class);
});

test('resolved reconciliation exceptions are purged', function () {
    FiscalSettings::saveFromForm([
        'fiscal_year_start_month' => 1,
        'fiscal_year_start_day' => 1,
        'purge_policy' => FiscalSettings::PURGE_RETAIN_7Y,
        'current_fiscal_year_label' => 'FY2026',
    ]);

    $close = closeFiscalYearForPurge();

    ReconciliationException::create([
        'exception_code' => 'RESOLVED_TEST',
        'domain' => 'master_account',
        'severity' => 'medium',
        'status' => ReconciliationException::STATUS_RESOLVED,
        'raised_at' => now()->subDay(),
        'resolved_at' => now(),
    ]);

    ReconciliationException::create([
        'exception_code' => 'OPEN_TEST',
        'domain' => 'master_account',
        'severity' => 'medium',
        'status' => ReconciliationException::STATUS_OPEN,
        'raised_at' => now(),
    ]);

    $summary = app(FiscalCloseService::class)->executeTierAPurge($close->fresh());

    expect($summary['reconciliation_exceptions'])->toBe(1)
        ->and(ReconciliationException::query()->count())->toBe(1)
        ->and(ReconciliationException::query()->value('exception_code'))->toBe('OPEN_TEST');
});
