<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\CollectionSummaryExportService;
use App\Services\ContributionCycleService;
use App\Support\ContributionCollectionStatus;
use App\Support\ContributionCollectionSummaryState;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    Contribution::query()->delete();
    Loan::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);

    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $this->accounting = app(AccountingService::class);
    $this->cycles = app(ContributionCycleService::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

function exportContributionsSummaryCsv(int $month, int $year): string
{
    $response = app(CollectionSummaryExportService::class)->downloadCsv($month, $year);
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

test('contributions summary export uses contributions-summary csv utf8 filename and bom', function () {
    [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();

    $response = app(CollectionSummaryExportService::class)->downloadCsv($month, $year);

    expect($response->headers->get('content-type'))->toBe('text/csv; charset=UTF-8')
        ->and($response->headers->get('content-disposition'))->toContain('contributions-summary-'.$year.'-'.sprintf('%02d', $month).'.csv');

    $csv = exportContributionsSummaryCsv($month, $year);

    expect($csv)->toStartWith("\xEF\xBB\xBF");
});

test('contributions summary export includes due and paid members with state column', function () {
    [$month, $year] = $this->cycles->currentOpenPeriod();

    $dueMember = Member::create([
        'member_number' => 'CON-DUE-1',
        'name' => 'Due Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($dueMember);

    $paidMember = Member::create([
        'member_number' => 'CON-PAID-1',
        'name' => 'Paid Member',
        'monthly_contribution_amount' => 750,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($paidMember);

    Contribution::create([
        'member_id' => $paidMember->id,
        'period' => Contribution::periodDate($month, $year),
        'amount' => 750,
        'amount_due' => 750,
        'amount_collected' => 750,
        'status' => 'posted',
        'collection_status' => ContributionCollectionStatus::COLLECTED,
        'posted_at' => now(),
    ]);

    $csv = exportContributionsSummaryCsv($month, $year);

    expect($csv)->toContain('CON-DUE-1')
        ->and($csv)->toContain('Due Member')
        ->and($csv)->toContain(',due,')
        ->and($csv)->toContain('CON-PAID-1')
        ->and($csv)->toContain('Paid Member')
        ->and($csv)->toContain(',paid,');
});

test('contributions summary export excludes inactive grace exempt and emi exempt members', function () {
    [$month, $year] = $this->cycles->currentOpenPeriod();

    $inactiveMember = Member::create([
        'member_number' => 'CON-INACTIVE',
        'name' => 'Inactive Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'inactive',
        'contribution_cycles_active' => false,
    ]);
    $this->accounting->createMemberAccounts($inactiveMember);

    $graceMember = Member::create([
        'member_number' => 'CON-GRACE',
        'name' => 'Grace Exempt Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($graceMember);

    Loan::create([
        'member_id' => $graceMember->id,
        'amount' => 6000,
        'amount_requested' => 6000,
        'amount_approved' => 6000,
        'amount_disbursed' => 6000,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'has_grace_cycle' => true,
        'grace_cycles' => 1,
        'first_repayment_month' => 7,
        'first_repayment_year' => 2026,
        'applied_at' => Carbon::parse('2026-06-21'),
        'disbursed_at' => Carbon::parse('2026-06-21'),
    ]);

    $emiMember = Member::create([
        'member_number' => 'CON-EMI',
        'name' => 'Emi Exempt Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($emiMember);

    Loan::create([
        'member_id' => $emiMember->id,
        'amount' => 6000,
        'amount_requested' => 6000,
        'amount_approved' => 6000,
        'amount_disbursed' => 6000,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'has_grace_cycle' => false,
        'grace_cycles' => 0,
        'first_repayment_month' => $month,
        'first_repayment_year' => $year,
        'applied_at' => Carbon::parse('2026-01-01'),
        'disbursed_at' => Carbon::parse('2026-01-01'),
    ]);

    $csv = exportContributionsSummaryCsv($month, $year);

    expect($csv)->not->toContain('CON-INACTIVE')
        ->and($csv)->not->toContain('Inactive Member')
        ->and($csv)->not->toContain('CON-GRACE')
        ->and($csv)->not->toContain('Grace Exempt Member')
        ->and($csv)->not->toContain('CON-EMI')
        ->and($csv)->not->toContain('Emi Exempt Member')
        ->and(ContributionCollectionSummaryState::resolve($graceMember, $month, $year, null))
        ->toBe(ContributionCollectionSummaryState::GRACE_EXEMPT)
        ->and(ContributionCollectionSummaryState::resolve($emiMember, $month, $year, null))
        ->toBe(ContributionCollectionSummaryState::EMI_EXEMPT);
});
