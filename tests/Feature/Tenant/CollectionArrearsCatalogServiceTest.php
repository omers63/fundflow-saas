<?php

declare(strict_types=1);

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationSnapshot;
use App\Models\Tenant\Setting;
use App\Services\AccountingService;
use App\Services\CollectionArrearsCatalogService;
use App\Services\ReconciliationReportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    app()->setLocale('en');
    Carbon::setTestNow(null);
    Cache::flush();

    LoanInstallment::query()->delete();
    Loan::query()->delete();
    Contribution::withTrashed()->forceDelete();
    Member::query()->delete();

    $this->service = app(CollectionArrearsCatalogService::class);
    $this->accounting = app(AccountingService::class);
});

test('open cycle snapshot totals contribution and emi arrears for the anchor period', function () {
    Setting::set('contribution', 'cycle_start_day', '6');
    Carbon::setTestNow(Carbon::parse('2025-10-15'));

    $member = Member::create([
        'member_number' => 'ARR-SNAP-1',
        'name' => 'Arrears Snapshot Borrower',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 12_000,
        'amount_requested' => 12_000,
        'amount_approved' => 12_000,
        'amount_disbursed' => 12_000,
        'interest_rate' => 10,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2024-01-01'),
        'disbursed_at' => Carbon::parse('2024-01-01'),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::parse('2025-09-05'),
        'status' => 'pending',
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 2,
        'amount' => 1000,
        'due_date' => Carbon::parse('2025-11-05'),
        'status' => 'pending',
    ]);

    $snapshot = $this->service->openCycleSnapshot();

    expect($snapshot['emi_arrears_installments'])->toBe(1)
        ->and($snapshot['emi_arrears_members'])->toBe(1)
        ->and($snapshot['total_items'])->toBe(1)
        ->and($this->service->catalogConsistencyIssues()['issue_count'])->toBe(0);
});

test('emi arrears exclude installments when a posted contribution blocks repayment for that cycle', function () {
    Setting::set('contribution', 'cycle_start_day', '6');
    Carbon::setTestNow(Carbon::parse('2025-10-15'));

    $member = Member::create([
        'member_number' => 'ARR-BLOCK-1',
        'name' => 'Blocked EMI Borrower',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 12_000,
        'amount_requested' => 12_000,
        'amount_approved' => 12_000,
        'amount_disbursed' => 12_000,
        'interest_rate' => 10,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2024-01-01'),
        'disbursed_at' => Carbon::parse('2024-01-01'),
        'first_repayment_month' => 2,
        'first_repayment_year' => 2024,
        // Repayment ended before Sep 2025 so the member is not contribution-exempt that cycle.
        'settled_at' => Carbon::parse('2025-06-01'),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::parse('2025-09-10'),
        'status' => 'pending',
    ]);

    Contribution::create([
        'member_id' => $member->id,
        'amount' => 500,
        'period' => Contribution::periodDate(9, 2025),
        'status' => 'posted',
        'collection_status' => 'collected',
        'due_date' => Carbon::parse('2025-10-05'),
        'paid_at' => Carbon::parse('2025-09-10'),
    ]);

    $snapshot = $this->service->openCycleSnapshot();

    expect($snapshot['emi_arrears_installments'])->toBe(0)
        ->and($snapshot['emi_arrears_members'])->toBe(0);
});

test('reconciliation report includes collection arrears catalog check', function () {
    $report = app(ReconciliationReportService::class)->buildReport(
        ReconciliationSnapshot::MODE_REALTIME,
    );

    $check = $report['checks']['collection_arrears_catalog'];

    expect($check['severity'])->toBe('ok')
        ->and($check)->toHaveKeys(['snapshot', 'issue_count', 'period_label', 'issues'])
        ->and($report['summary']['headline_checks'])->toHaveKey('collection_arrears_catalog');
});
