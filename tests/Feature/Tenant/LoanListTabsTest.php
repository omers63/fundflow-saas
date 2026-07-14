<?php

declare(strict_types=1);

use App\Filament\Support\LoanListTableHeaderActions;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\Loans\Pages\ListLoans;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Central\Tenant;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanEmiCollectionCatalogService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    $tenant = Tenant::find('testing');
    $this->domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $this->domain)->exists()) {
        $tenant->domains()->create(['domain' => $this->domain]);
    }

    $admin = User::create([
        'name' => 'Loans Admin',
        'email' => 'loans-tabs@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($admin, 'tenant');
});

test('loans list defaults to collection tab with collect segment', function () {
    Livewire::test(ListLoans::class)
        ->assertSuccessful()
        ->assertSet('activeTab', 'collection')
        ->assertSet('collectionSegment', 'collect')
        ->assertSee(__('To collect'), false);

    expect(LoanResource::listUrl())
        ->toBe(LoanResource::listUrl('collection'))
        ->not->toContain('tab=');

    expect(LoanResource::listTabUrl('emi_collect'))
        ->not->toContain('tab=emi_collect');
});

test('legacy emi collect tab url redirects to collection segment', function () {
    $path = parse_url(LoanResource::listTabUrl('emi_collect'), PHP_URL_PATH) ?? '/admin/loans';
    $query = parse_url(LoanResource::listTabUrl('emi_collect'), PHP_URL_QUERY);

    $this->get('http://'.$this->domain.$path.($query ? '?'.$query : ''))
        ->assertSuccessful()
        ->assertSee(__('Collection'), false)
        ->assertSee(__('To collect'), false);
});

test('legacy overdue installments tab url maps to delinquency view', function () {
    expect(LoanResource::listTabUrl('overdue_installments'))
        ->toContain('tab=delinquency')
        ->not->toContain('tab=overdue_installments');

    $path = parse_url(LoanResource::listTabUrl('overdue_installments'), PHP_URL_PATH) ?? '/admin/loans';
    $query = parse_url(LoanResource::listTabUrl('overdue_installments'), PHP_URL_QUERY);

    $this->get('http://'.$this->domain.$path.($query ? '?'.$query : ''))
        ->assertSuccessful()
        ->assertSee(__('Delinquency'), false)
        ->assertSee(__('Overdue installments'), false);
});

test('legacy guarantor exposure tab url maps to delinquency guarantor view', function () {
    expect(LoanResource::listTabUrl('guarantor_exposure'))
        ->toContain('tab=delinquency')
        ->toContain('view=guarantor');
});

test('legacy eligibility reviews tab url maps to portfolio eligibility view', function () {
    expect(LoanResource::listTabUrl('eligibility_reviews'))
        ->toContain('tab=portfolio')
        ->toContain('portfolioView=eligibility');
});

test('collected segment url omits default tab parameter', function () {
    expect(LoanResource::listTabUrl('emi_collected'))
        ->toContain('segment=collected')
        ->not->toContain('tab=collection');
});

test('portfolio tab exposes import and export header actions', function () {
    $component = Livewire::test(ListLoans::class)
        ->set('activeTab', 'portfolio');

    $names = LoanListTableHeaderActions::flattenActionNames(
        $component->instance()->getTable()->getHeaderActions(),
    );

    expect($names)->toContain('importLoans', 'exportLoans', 'importRepayments', 'exportRepayments');
});

test('collection tab exposes cycle selector and drives selected period', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $cycles = app(ContributionCycleService::class);
    [$openMonth, $openYear] = $cycles->currentOpenPeriod();
    $previous = Carbon::create($openYear, $openMonth, 1)->subMonthNoOverflow();
    $previousKey = $cycles->contributionCycleKey((int) $previous->month, (int) $previous->year);

    Livewire::test(ListLoans::class)
        ->assertSet('activeTab', 'collection')
        ->assertSee(__('Collection cycle'), false)
        ->set('selectedCycle', $previousKey)
        ->assertSet('selectedCycle', $previousKey)
        ->assertSee($cycles->periodLabel((int) $previous->month, (int) $previous->year), false);

    Carbon::setTestNow();
});

test('collection tab table exposes cycle collection header group', function () {
    $component = Livewire::test(ListLoans::class)
        ->set('activeTab', 'collection')
        ->set('collectionSegment', 'collect');

    expect($component->instance()->getTable()->getHeaderActions())->toHaveCount(1)
        ->and($component->instance()->getTable()->getHeaderActions()[0]->getLabel())->toBe(__('Cycle collection'));
});

test('collected segment url preserves historical cycle key', function () {
    $url = LoanResource::listCollectionSegmentUrl('collected', '2025-10');

    expect($url)
        ->toContain('segment=collected')
        ->toContain('cycle=2025-10')
        ->not->toContain('/loans/2025-10');

    $path = parse_url($url, PHP_URL_PATH) ?? '/admin/loans/loans';
    $query = parse_url($url, PHP_URL_QUERY);

    $this->get('http://'.$this->domain.$path.($query ? '?'.$query : ''))
        ->assertSuccessful()
        ->assertSee(__('Collected'), false);
});

test('collected installment table links rows to the loan view', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $cycles = app(ContributionCycleService::class);
    [$openMonth, $openYear] = $cycles->currentOpenPeriod();
    $previous = Carbon::create($openYear, $openMonth, 1)->subMonthNoOverflow();
    $month = (int) $previous->month;
    $year = (int) $previous->year;
    $cycleKey = $cycles->contributionCycleKey($month, $year);

    $accounting = app(AccountingService::class);
    $member = Member::create([
        'member_number' => 'LOAN-COL-URL',
        'name' => 'Collected URL Borrower',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 6000,
        'amount_requested' => 6000,
        'amount_approved' => 6000,
        'amount_disbursed' => 6000,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2026-01-01'),
        'disbursed_at' => Carbon::parse('2026-01-01'),
    ]);

    $installment = LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::create($year, $month, 10),
        'status' => 'paid',
        'paid_at' => now(),
    ]);

    $component = Livewire::test(ListLoans::class)
        ->set('selectedCycle', $cycleKey)
        ->set('collectionSegment', 'collected');

    $table = $component->instance()->getTable();
    $recordUrl = $table->getRecordUrl($installment);

    expect($recordUrl)->toBe(LoanResource::getUrl('view', ['record' => $loan->id]))
        ->and($recordUrl)->not->toContain('/'.$installment->getKey());

    $memberUrl = MemberResource::getUrl('view', ['record' => $member]);

    expect($table->getColumn('loan.member.member_number')->record($installment)->getUrl())->toBe($memberUrl)
        ->and($table->getColumn('loan.member.name')->record($installment)->getUrl())->toBe($memberUrl)
        ->and((string) $table->getColumn('outstanding')->getLabel())->toContain(__('Loan outstanding'))
        ->and((string) $table->getColumn('outstanding')->getLabel())->toContain('fi-ff-label-with-icon');

    $outstandingColumn = $table->getColumn('outstanding')->record($installment->fresh(['loan']));
    $formattedOutstanding = $outstandingColumn->formatState($outstandingColumn->getState());
    expect($outstandingColumn->getState())->toBeInstanceOf(Loan::class)
        ->and((string) $formattedOutstanding)->toContain('ff-loan-outstanding-cell');

    Carbon::setTestNow();
});

test('to collect table links rows to the member loan not the member profile', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $cycles = app(ContributionCycleService::class);
    [$month, $year] = $cycles->currentOpenPeriod();

    $accounting = app(AccountingService::class);
    $member = Member::create([
        'member_number' => 'EMI-77',
        'name' => 'EMI Collect Borrower',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 6000,
        'amount_requested' => 6000,
        'amount_approved' => 6000,
        'amount_disbursed' => 6000,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2026-01-01'),
        'disbursed_at' => Carbon::parse('2026-01-01'),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::create($year, $month, 10),
        'status' => 'pending',
    ]);

    $component = Livewire::test(ListLoans::class)
        ->set('collectionSegment', 'collect');

    $table = $component->instance()->getTable();
    $recordUrl = $table->getRecordUrl($member);

    $memberUrl = MemberResource::getUrl('view', ['record' => $member]);

    expect($recordUrl)->toBe(LoanResource::getUrl('view', ['record' => $loan->id]))
        ->and($recordUrl)->not->toBe($memberUrl)
        ->and($table->getColumn('member_number')->record($member)->getUrl($member->member_number))->toBe($memberUrl)
        ->and($table->getColumn('name')->record($member)->getUrl($member->name))->toBe($memberUrl)
        ->and((string) $table->getColumn('loan_outstanding')->getLabel())->toContain(__('Loan outstanding'))
        ->and((string) $table->getColumn('loan_outstanding')->getLabel())->toContain('fi-ff-label-with-icon');

    Carbon::setTestNow();
});

test('collection tabs can sort by loan outstanding column', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $cycles = app(ContributionCycleService::class);
    [$month, $year] = $cycles->currentOpenPeriod();

    $accounting = app(AccountingService::class);
    $member = Member::create([
        'member_number' => 'EMI-SORT-OUT',
        'name' => 'EMI Outstanding Sort Borrower',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 6000,
        'amount_requested' => 6000,
        'amount_approved' => 6000,
        'amount_disbursed' => 6000,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2026-01-01'),
        'disbursed_at' => Carbon::parse('2026-01-01'),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::create($year, $month, 10),
        'status' => 'pending',
    ]);

    Livewire::test(ListLoans::class)
        ->set('collectionSegment', 'collect')
        ->call('sortTable', 'loan_outstanding')
        ->assertSuccessful();

    LoanInstallment::query()->where('loan_id', $loan->id)->update([
        'status' => 'paid',
        'paid_at' => now(),
    ]);

    Livewire::test(ListLoans::class)
        ->set('collectionSegment', 'collected')
        ->call('sortTable', 'outstanding')
        ->assertSuccessful();

    LoanInstallment::query()->where('loan_id', $loan->id)->update([
        'status' => 'pending',
        'paid_at' => null,
    ]);

    Livewire::test(ListLoans::class)
        ->set('selectedCycle', $cycles->contributionCycleKey((int) Carbon::create($year, $month, 1)->addMonth()->month, (int) Carbon::create($year, $month, 1)->addMonth()->year))
        ->set('collectionSegment', 'arrears')
        ->call('sortTable', 'outstanding')
        ->assertSuccessful();

    Carbon::setTestNow();
});

test('collected segment pill shows installment count badge', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $cycles = app(ContributionCycleService::class);
    [$month, $year] = $cycles->currentOpenPeriod();

    $accounting = app(AccountingService::class);
    $member = Member::create([
        'member_number' => 'LOAN-COL-BADGE',
        'name' => 'Collected Badge Borrower',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 6000,
        'amount_requested' => 6000,
        'amount_approved' => 6000,
        'amount_disbursed' => 6000,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2026-01-01'),
        'disbursed_at' => Carbon::parse('2026-01-01'),
    ]);

    $before = LoanResource::collectedEmiInstallmentCount();

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::create($year, $month, 10),
        'status' => 'paid',
        'paid_at' => now(),
    ]);

    LoanResource::flushListCountCaches();

    expect(LoanResource::collectedEmiInstallmentCount())->toBe($before + 1);

    Livewire::test(ListLoans::class)
        ->assertSuccessful()
        ->assertSee(__('Collected'), false)
        ->assertSee('>'.($before + 1).'</span>', false);

    Carbon::setTestNow();
});

test('collected list excludes installments for loans paid off before the labelled cycle', function () {
    Setting::set('contribution', 'cycle_start_day', '6');

    $catalog = app(LoanEmiCollectionCatalogService::class);
    $accounting = app(AccountingService::class);

    $member = Member::create([
        'member_number' => 'LOAN-164-LIKE',
        'name' => 'Early Payoff Borrower',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 20_000,
        'amount_requested' => 20_000,
        'amount_approved' => 20_000,
        'amount_disbursed' => 20_000,
        'interest_rate' => 10,
        'term_months' => 20,
        'monthly_repayment' => 1000,
        'total_repaid' => 20_000,
        'status' => 'completed',
        'applied_at' => Carbon::parse('2024-01-01'),
        'disbursed_at' => Carbon::parse('2024-01-01'),
    ]);

    $paidAt = Carbon::parse('2025-07-08');

    $julyCycleInstallment = LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 12,
        'amount' => 1000,
        'due_date' => Carbon::parse('2025-08-05'),
        'status' => 'paid',
        'paid_at' => $paidAt,
    ]);

    $futureDueInstallment = LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 19,
        'amount' => 1000,
        'due_date' => Carbon::parse('2025-10-05'),
        'status' => 'paid',
        'paid_at' => $paidAt,
    ]);

    expect($catalog->collectedInstallmentsQuery(7, 2025)->pluck('id'))
        ->toContain($julyCycleInstallment->id)
        ->and($catalog->collectedInstallmentsQuery(10, 2025)->pluck('id'))
        ->not->toContain($futureDueInstallment->id);
});

test('collected list includes arrears installments paid during the labelled cycle', function () {
    Setting::set('contribution', 'cycle_start_day', '6');

    $catalog = app(LoanEmiCollectionCatalogService::class);
    $accounting = app(AccountingService::class);

    $member = Member::create([
        'member_number' => 'LOAN-171-LIKE',
        'name' => 'Late Schedule Completion Borrower',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 10_000,
        'amount_requested' => 10_000,
        'amount_approved' => 10_000,
        'amount_disbursed' => 10_000,
        'interest_rate' => 10,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'total_repaid' => 10_000,
        'status' => 'completed',
        'applied_at' => Carbon::parse('2024-01-01'),
        'disbursed_at' => Carbon::parse('2024-01-01'),
        'settled_at' => Carbon::parse('2025-10-28'),
    ]);

    $paidAt = Carbon::parse('2025-10-28');

    $juneCycleInstallment = LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 6,
        'amount' => 1000,
        'due_date' => Carbon::parse('2025-06-10'),
        'status' => 'paid',
        'paid_at' => $paidAt,
    ]);

    expect($catalog->collectedInstallmentsQuery(6, 2025)->pluck('id'))
        ->toContain($juneCycleInstallment->id)
        ->and($catalog->collectedInstallmentsQuery(10, 2025)->pluck('id'))
        ->not->toContain($juneCycleInstallment->id);
});

test('collected list assigns installment to cycle containing due date not payment date', function () {
    Setting::set('contribution', 'cycle_start_day', '6');

    $catalog = app(LoanEmiCollectionCatalogService::class);
    $accounting = app(AccountingService::class);

    $member = Member::create([
        'member_number' => 'LOAN-170-LIKE',
        'name' => 'Early October Payment Borrower',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 10_000,
        'amount_requested' => 10_000,
        'amount_approved' => 10_000,
        'amount_disbursed' => 10_000,
        'interest_rate' => 10,
        'term_months' => 20,
        'monthly_repayment' => 1000,
        'total_repaid' => 1000,
        'status' => 'active',
        'applied_at' => Carbon::parse('2024-01-01'),
        'disbursed_at' => Carbon::parse('2024-01-01'),
    ]);

    $installment = LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 19,
        'amount' => 1000,
        'due_date' => Carbon::parse('2025-10-10'),
        'status' => 'paid',
        'paid_at' => Carbon::parse('2025-10-04'),
    ]);

    expect($catalog->collectedInstallmentsQuery(10, 2025)->pluck('id'))
        ->toContain($installment->id)
        ->and($catalog->collectedInstallmentsQuery(9, 2025)->pluck('id'))
        ->not->toContain($installment->id);
});

test('collected list uses actual repayment cash for final legacy top-up installment', function () {
    Setting::set('contribution', 'cycle_start_day', '6');

    $catalog = app(LoanEmiCollectionCatalogService::class);

    $member = Member::create([
        'member_number' => 'LOAN-193-TOPUP',
        'name' => 'Final Top-up Borrower',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 12_000,
        'amount_requested' => 12_000,
        'amount_approved' => 12_000,
        'amount_disbursed' => 12_000,
        'master_portion' => 12_000,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'status' => 'completed',
        'applied_at' => Carbon::parse('2024-06-01'),
        'disbursed_at' => Carbon::parse('2024-06-01'),
        'settled_at' => Carbon::parse('2025-10-01'),
    ]);

    $installment = LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 12,
        'amount' => 1000,
        'due_date' => Carbon::parse('2026-01-05'),
        'status' => 'paid',
        'paid_at' => Carbon::parse('2025-10-01'),
        'amount_collected' => 0,
    ]);

    $loan->repayments()->create([
        'amount' => 900,
        'paid_at' => Carbon::parse('2025-10-01'),
        'notes' => 'legacy-import:test|2025-10-01|900|loan_repayment',
    ]);

    $collected = $catalog->collectedInstallmentsQuery(12, 2025)->get();

    expect($collected->pluck('id'))->toContain($installment->id)
        ->and($collected->firstWhere('id', $installment->id)?->collectedCashAmount())->toBe(900.0);
});

test('delinquency tab exposes maintenance actions on overdue view', function () {
    Livewire::test(ListLoans::class)
        ->set('activeTab', 'delinquency')
        ->set('delinquencyView', 'overdue')
        ->mountTableAction('markOverdueInstallments')
        ->callMountedTableAction()
        ->assertNotified();
});

test('collection arrears segment lists unpaid installments before selected cycle', function () {
    Setting::set('contribution', 'cycle_start_day', '6');

    Carbon::setTestNow(Carbon::parse('2025-10-15'));

    $cycles = app(ContributionCycleService::class);
    $octoberKey = $cycles->contributionCycleKey(10, 2025);
    $accounting = app(AccountingService::class);
    $catalog = app(LoanEmiCollectionCatalogService::class);

    $member = Member::create([
        'member_number' => 'LOAN-ARR-1',
        'name' => 'EMI Arrears Borrower',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);

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

    expect(LoanResource::emiArrearsInstallmentCount())->toBe(1)
        ->and(LoanResource::listTabUrl('arrears'))->toContain('segment=arrears');

    $component = Livewire::test(ListLoans::class)
        ->set('selectedCycle', $octoberKey)
        ->set('collectionSegment', 'arrears')
        ->assertSuccessful()
        ->assertSet('collectionSegment', 'arrears')
        ->assertSee(__('Arrears'), false)
        ->assertSee(__('Unpaid installments from labelled cycles before :period.', [
            'period' => $cycles->periodLabel(10, 2025),
        ]), false);

    $arrearsInstallment = $catalog->emiArrearsInstallmentsQuery(10, 2025, true)->first();
    $arrearsInstallment?->loadMissing('loan');

    $outstandingColumn = $component->instance()->getTable()->getColumn('outstanding')->record($arrearsInstallment);
    $formattedOutstanding = $outstandingColumn->formatState($outstandingColumn->getState());

    expect($arrearsInstallment)->not->toBeNull()
        ->and($outstandingColumn->getState())->toBeInstanceOf(Loan::class)
        ->and((string) $formattedOutstanding)->toContain('ff-loan-outstanding-cell');

    expect($catalog->emiArrearsInstallmentCount(10, 2025, true))->toBe(1);

    Carbon::setTestNow();
});
