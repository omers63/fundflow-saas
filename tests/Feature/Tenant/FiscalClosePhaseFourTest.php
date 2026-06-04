<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FiscalClose;
use App\Models\Tenant\FiscalCloseMemberSnapshot;
use App\Models\Tenant\FundAuditLog;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\FiscalClose\FiscalCloseExportService;
use App\Services\FiscalClose\FiscalCloseService;
use App\Support\ContributionCollectionStatus;
use App\Support\FiscalSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Setting::query()->delete();
    FiscalClose::query()->delete();
    FiscalCloseMemberSnapshot::query()->delete();
    Transaction::query()->delete();
    Contribution::query()->forceDelete();
    LoanInstallment::query()->forceDelete();
    Loan::query()->delete();
    FundPosting::query()->delete();
    FundAuditLog::query()->delete();
    Account::query()->delete();
    Member::query()->delete();
    Storage::disk('local')->deleteDirectory('fiscal-closes');

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

function createPhaseFourAdmin(): User
{
    return User::create([
        'name' => 'Phase Four Admin',
        'email' => 'phase-four-'.uniqid('', true).'@fund.test',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);
}

function createPhaseFourMember(float $cash = 100, float $fund = 200): Member
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

function closeFiscalYearForPhaseFour(?Member $member = null): FiscalClose
{
    $member ??= createPhaseFourMember();
    $admin = createPhaseFourAdmin();
    $close = app(FiscalCloseService::class)->prepareSnapshot('FY2026', Carbon::parse('2026-12-31'), $admin);

    return app(FiscalCloseService::class)->approveAndRollForward($close, $admin);
}

test('generate exports writes manifest and files after snapshot', function () {
    $close = closeFiscalYearForPhaseFour();

    Transaction::create([
        'account_id' => Account::masterCash()->id,
        'type' => 'credit',
        'amount' => 10,
        'balance_after' => 110,
        'description' => 'Export test',
        'transacted_at' => Carbon::parse('2026-06-01'),
    ]);

    $manifest = app(FiscalCloseService::class)->generateExports($close->fresh());

    expect($manifest)->toHaveKey('files')
        ->and($manifest['files'])->toHaveKeys(['gl', 'arrears_aging', 'loan_portfolio', 'readiness_report'])
        ->and($close->fresh()->hasExports())->toBeTrue();

    foreach ($manifest['files'] as $path) {
        expect(Storage::disk('local')->exists($path))->toBeTrue();
    }
});

test('tier a purge is blocked until exports exist when archive policy is enabled', function () {
    $close = closeFiscalYearForPhaseFour();

    expect(fn () => app(FiscalCloseService::class)->executeTierAPurge($close->fresh()))
        ->toThrow(InvalidArgumentException::class, 'Generate archive exports');
});

test('full tier a and tier b purge completes close under archive policy', function () {
    $member = createPhaseFourMember();
    $close = closeFiscalYearForPhaseFour($member);

    Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate(6, 2026),
        'amount' => 50,
        'status' => 'posted',
        'collection_status' => ContributionCollectionStatus::COLLECTED,
        'amount_due' => 50,
        'amount_collected' => 50,
        'posted_at' => Carbon::parse('2026-06-05'),
    ]);

    Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate(11, 2026),
        'amount' => 50,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::OVERDUE,
        'amount_due' => 50,
        'amount_collected' => 0,
    ]);

    $loan = Loan::factory()->create(['member_id' => $member->id]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 100,
        'due_date' => '2026-05-01',
        'status' => 'paid',
        'paid_at' => Carbon::parse('2026-05-10'),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 2,
        'amount' => 100,
        'due_date' => '2027-01-01',
        'status' => 'pending',
    ]);

    FundPosting::create([
        'member_id' => $member->id,
        'posting_date' => '2026-04-01',
        'amount' => 25,
        'status' => 'accepted',
    ]);

    FundPosting::create([
        'member_id' => $member->id,
        'posting_date' => '2026-04-01',
        'amount' => 30,
        'status' => 'pending',
    ]);

    FundAuditLog::create([
        'event_type' => 'TEST_OLD',
        'domain' => 'fiscal_close',
        'payload' => ['note' => 'old'],
        'occurred_at' => Carbon::parse('2026-03-01'),
    ]);

    app(FiscalCloseService::class)->generateExports($close->fresh());

    $tierA = app(FiscalCloseService::class)->executeTierAPurge($close->fresh());

    expect($close->fresh()->status)->toBe(FiscalClose::STATUS_ROLLED_FORWARD)
        ->and($tierA)->toHaveKeys(['transactions', 'bank_transactions', 'reconciliation_exceptions'])
        ->and($close->fresh()->canPurgeTierB())->toBeTrue();

    $tierB = app(FiscalCloseService::class)->executeTierBPurge($close->fresh());

    expect($close->fresh()->status)->toBe(FiscalClose::STATUS_PURGED)
        ->and($tierB['contributions'])->toBe(1)
        ->and($tierB['loan_installments'])->toBe(1)
        ->and($tierB['fund_postings'])->toBe(1)
        ->and(Contribution::query()->count())->toBe(1)
        ->and(LoanInstallment::query()->count())->toBe(1)
        ->and(FundPosting::query()->count())->toBe(1)
        ->and(FundAuditLog::query()->count())->toBe(1)
        ->and(FundAuditLog::query()->value('event_type'))->toBe('FISCAL_CLOSE_TIER_B_PURGE_COMPLETED');
});

test('retain 7y policy completes after tier a only', function () {
    FiscalSettings::saveFromForm([
        'fiscal_year_start_month' => 1,
        'fiscal_year_start_day' => 1,
        'purge_policy' => FiscalSettings::PURGE_RETAIN_7Y,
        'current_fiscal_year_label' => 'FY2026',
    ]);

    $close = closeFiscalYearForPhaseFour();

    $summary = app(FiscalCloseService::class)->executeTierAPurge($close->fresh());

    expect($close->fresh()->status)->toBe(FiscalClose::STATUS_PURGED)
        ->and($summary['tier'])->toBe('a')
        ->and(fn () => app(FiscalCloseService::class)->executeTierBPurge($close->fresh()))
        ->toThrow(InvalidArgumentException::class);
});

test('export download resolves manifest paths', function () {
    $close = closeFiscalYearForPhaseFour();
    $manifest = app(FiscalCloseService::class)->generateExports($close->fresh());

    $path = app(FiscalCloseExportService::class)->resolveDownloadPath(
        $close->fresh(),
        'readiness_report',
    );

    expect($path)->toBe($manifest['files']['readiness_report']);
});
