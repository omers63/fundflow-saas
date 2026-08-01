<?php

declare(strict_types=1);

use App\Filament\Support\LoanDelinquencyTables;
use App\Filament\Tenant\Pages\DelinquencyWorkspacePage;
use App\Filament\Tenant\Support\DelinquencyTabRegistry;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\Loans\LoanDelinquencyService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    app()->setLocale('en');

    $admin = User::create([
        'name' => 'Delinquency Tabs Admin',
        'email' => 'delinquency-tabs-'.uniqid().'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($admin, 'tenant');
});

it('switches delinquency side tabs via setSideTab', function () {
    Livewire::test(DelinquencyWorkspacePage::class)
        ->assertSet('sideTab', 'overview')
        ->call('setSideTab', 'overdue')
        ->assertSet('sideTab', 'overdue')
        ->assertSuccessful()
        ->call('setSideTab', 'guarantor')
        ->assertSet('sideTab', 'guarantor')
        ->assertSuccessful()
        ->call('setSideTab', 'policy')
        ->assertSet('sideTab', 'policy')
        ->assertSuccessful()
        ->call('setSideTab', 'related')
        ->assertSet('sideTab', 'related')
        ->assertSuccessful()
        ->call('setSideTab', 'overview')
        ->assertSet('sideTab', 'overview')
        ->assertSuccessful();
});

it('switches delinquency side tabs via Livewire without full navigation', function () {
    $html = Livewire::test(DelinquencyWorkspacePage::class)->html();

    foreach (array_keys(DelinquencyTabRegistry::tabs()) as $tab) {
        expect($html)->toContain(DelinquencyTabRegistry::url($tab));
    }

    expect($html)
        ->toContain('ff-tenant-tab-pills__item no-underline')
        ->toContain('wire:click.prevent="setSideTab');
});

it('exposes admin loan transfer on overdue and not on guarantor', function () {
    Livewire::test(DelinquencyWorkspacePage::class, ['sideTab' => 'overdue'])
        ->assertSuccessful()
        ->assertTableActionExists('transferLoanAdmin');

    Livewire::test(DelinquencyWorkspacePage::class, ['sideTab' => 'guarantor'])
        ->assertSuccessful()
        ->assertTableActionDoesNotExist('view_loan')
        ->assertTableActionDoesNotExist('view')
        ->assertTableActionDoesNotExist('transferGuarantorLiability')
        ->assertTableActionDoesNotExist('restoreBorrowerLiability')
        ->assertTableActionDoesNotExist('transferLoanAdmin');
});

it('defers delinquency insights until the section is unfolded', function () {
    Livewire::test(DelinquencyWorkspacePage::class, ['sideTab' => 'overview'])
        ->assertSuccessful()
        ->assertSee(__('Expand to load KPIs and risk summary.'))
        ->assertDontSee(__('Collections are current'))
        ->call('unfoldSection', 'insights')
        ->assertSee(__('Collections are current'));
});

it('shows overdue columns after Livewire tab switch from overview', function () {
    $accounting = app(AccountingService::class);

    $member = Member::create([
        'member_number' => 'MEM-DEL-COLS',
        'name' => 'Column Visibility Member',
        'monthly_contribution_amount' => 100,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 5_000,
        'amount_requested' => 5_000,
        'amount_approved' => 5_000,
        'amount_disbursed' => 5_000,
        'interest_rate' => 10,
        'term_months' => 5,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => now()->subYear(),
        'disbursed_at' => now()->subYear(),
    ]);

    $installment = LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => now()->subMonths(2)->startOfMonth(),
        'status' => 'overdue',
    ]);

    Livewire::test(DelinquencyWorkspacePage::class, ['sideTab' => 'overview'])
        ->assertSuccessful()
        ->call('setSideTab', 'overdue')
        ->assertSet('sideTab', 'overdue')
        ->call('loadTable')
        ->assertCanSeeTableRecords([$installment])
        ->assertSee('Column Visibility Member', false)
        ->assertSee('#'.$loan->id, false);
});

it('paginates overdue installments without resetting to page one', function () {
    $accounting = app(AccountingService::class);

    $member = Member::create([
        'member_number' => 'MEM-DEL-PAGE',
        'name' => 'Pagination Member',
        'monthly_contribution_amount' => 100,
        'joined_at' => now()->subYear(),
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
        'applied_at' => now()->subYear(),
        'disbursed_at' => now()->subYear(),
    ]);

    $installments = collect(range(1, 12))->map(function (int $number) use ($loan) {
        return LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $number,
            'amount' => 1000,
            'due_date' => now()->subMonths(13 - $number)->startOfMonth(),
            'status' => 'overdue',
        ]);
    });

    $page = Livewire::test(DelinquencyWorkspacePage::class, ['sideTab' => 'overdue'])
        ->assertSuccessful()
        ->call('loadTable')
        ->assertCanSeeTableRecords($installments->take(10)->all())
        ->assertCanNotSeeTableRecords([$installments->last()]);

    $page->call('setPage', 2)
        ->assertSuccessful()
        ->assertSet('paginators.delinquency_overduePage', 2)
        ->assertCanSeeTableRecords([$installments->last()])
        ->assertCanNotSeeTableRecords([$installments->first()]);
});

it('includes completed loans with guarantor-paid installments on the guarantor tab', function () {
    $accounting = app(AccountingService::class);

    $borrower = Member::create([
        'member_number' => 'MEM-DEL-G-B',
        'name' => 'Completed Guarantor Borrower',
        'monthly_contribution_amount' => 100,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $guarantor = Member::create([
        'member_number' => 'MEM-DEL-G-G',
        'name' => 'Completed Guarantor Guarantor',
        'monthly_contribution_amount' => 100,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($borrower);
    $accounting->createMemberAccounts($guarantor);

    $loan = Loan::create([
        'member_id' => $borrower->id,
        'guarantor_member_id' => $guarantor->id,
        'amount' => 5_000,
        'amount_requested' => 5_000,
        'amount_approved' => 5_000,
        'amount_disbursed' => 5_000,
        'interest_rate' => 10,
        'term_months' => 5,
        'monthly_repayment' => 1000,
        'total_repaid' => 5_000,
        'status' => 'completed',
        'settled_at' => now()->subDay(),
        'guarantor_released_at' => now()->subDay(),
        'late_repayment_count' => 2,
        'applied_at' => now()->subYear(),
        'disbursed_at' => now()->subYear(),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 5,
        'amount' => 1000,
        'due_date' => now()->subMonths(2)->startOfMonth(),
        'status' => 'paid',
        'paid_at' => now()->subDay(),
        'paid_by_guarantor' => true,
    ]);

    expect(LoanDelinquencyTables::guarantorExposureQuery()->whereKey($loan->id)->exists())->toBeTrue();

    Livewire::test(DelinquencyWorkspacePage::class, ['sideTab' => 'guarantor'])
        ->assertSuccessful()
        ->call('loadTable')
        ->assertCanSeeTableRecords([$loan])
        ->assertSee('Completed Guarantor Borrower', false)
        ->assertSee('#'.$loan->id, false)
        ->assertSee(__('Guarantor paid'), false);
});

it('shows installment-based late count on the guarantor tab instead of the grace counter', function () {
    $accounting = app(AccountingService::class);

    $borrower = Member::create([
        'member_number' => 'MEM-DEL-LATE-B',
        'name' => 'Late Count Borrower',
        'monthly_contribution_amount' => 100,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $guarantor = Member::create([
        'member_number' => 'MEM-DEL-LATE-G',
        'name' => 'Late Count Guarantor',
        'monthly_contribution_amount' => 100,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($borrower);
    $accounting->createMemberAccounts($guarantor);

    $loan = Loan::create([
        'member_id' => $borrower->id,
        'guarantor_member_id' => $guarantor->id,
        'amount' => 9_000,
        'amount_requested' => 9_000,
        'amount_approved' => 9_000,
        'amount_disbursed' => 9_000,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 1500,
        'total_repaid' => 4500,
        'status' => 'active',
        // Grace counter under-counts relative to guarantor-paid installments.
        'late_repayment_count' => 1,
        'applied_at' => now()->subYear(),
        'disbursed_at' => now()->subYear(),
    ]);

    foreach ([1, 2, 3] as $number) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $number,
            'amount' => 1500,
            'due_date' => now()->subMonths(4 - $number)->startOfMonth(),
            'status' => 'paid',
            'paid_at' => now()->subDay(),
            'paid_by_guarantor' => true,
            'is_late' => $number === 1,
        ]);
    }

    $row = LoanDelinquencyTables::guarantorExposureQuery()->whereKey($loan->id)->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->late_repayment_count)->toBe(1)
        ->and((int) $row->late_installments_count)->toBe(3)
        ->and((int) $row->guarantor_paid_installments_count)->toBe(3);

    Livewire::test(DelinquencyWorkspacePage::class, ['sideTab' => 'guarantor'])
        ->assertSuccessful()
        ->call('loadTable')
        ->assertCanSeeTableRecords([$loan]);
});

it('includes at-risk arrears members on the policy breaches tab', function () {
    $accounting = app(AccountingService::class);

    $member = Member::create([
        'member_number' => 'MEM-DEL-AT-RISK',
        'name' => 'At Risk Policy Member',
        'monthly_contribution_amount' => 100,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 5_000,
        'amount_requested' => 5_000,
        'amount_approved' => 5_000,
        'amount_disbursed' => 5_000,
        'interest_rate' => 10,
        'term_months' => 5,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => now()->subYear(),
        'disbursed_at' => now()->subYear(),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => now()->subMonths(2)->startOfMonth(),
        'status' => 'overdue',
    ]);

    $delinquency = app(LoanDelinquencyService::class);

    expect($delinquency->membersWithOutstandingArrearsIds())->toContain($member->id)
        ->and($delinquency->policyQueueMemberIds())->toContain($member->id);

    Livewire::test(DelinquencyWorkspacePage::class, ['sideTab' => 'policy'])
        ->assertSuccessful()
        ->call('loadTable')
        ->assertCanSeeTableRecords([$member])
        ->assertSee('At Risk Policy Member', false)
        ->assertSee(__('At risk'), false);
});

it('loads each delinquency panel from the sideTab query string', function () {
    foreach (DelinquencyTabRegistry::TABS as $tab) {
        Livewire::test(DelinquencyWorkspacePage::class, ['sideTab' => $tab])
            ->assertSet('sideTab', $tab)
            ->assertSuccessful();
    }
});
