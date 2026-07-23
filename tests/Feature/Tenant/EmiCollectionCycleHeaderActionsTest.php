<?php

declare(strict_types=1);

use App\Filament\Support\ContributionCycleHeaderActions;
use App\Filament\Support\LoanEmiCollectionHeaderActions;
use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Notifications\Tenant\LoanRepaymentDueNotification;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\Loans\EmiCollectionSummaryExportService;
use App\Services\Loans\LoanEmiCollectionCatalogService;
use App\Services\Loans\LoanRepaymentService;
use App\Support\BusinessDaySettings;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    BusinessDaySettings::saveFromForm('2026-06-15');
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    Account::query()->delete();
    Member::query()->delete();
    Loan::query()->delete();
    LoanInstallment::query()->delete();
    User::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);

    $this->accounting = app(AccountingService::class);
    $this->cycles = app(ContributionCycleService::class);
});

afterEach(function () {
    BusinessDaySettings::saveFromForm(null);
    Carbon::setTestNow();
});

test('emi cycle collection group exposes four actions like contribution cycle collection', function () {
    $names = collect(LoanEmiCollectionHeaderActions::cycleCollectionGroup()->getActions())
        ->map(fn ($action) => $action->getName())
        ->all();

    expect($names)->toBe([
        'sendEmiDueNotifications',
        'exportEmiCollectionSummary',
        'runEmiCollectionCycle',
        'prepareOverdueEmis',
    ])
        ->and(LoanEmiCollectionHeaderActions::cycleCollectionGroup()->getLabel())->toBe(__('Cycle collection'));
});

test('run emi collection cycle uses collect oldest arrears first by default', function () {
    $action = LoanEmiCollectionHeaderActions::runEmiCollectionCycle();

    expect($action->getName())->toBe('runEmiCollectionCycle')
        ->and($action->getLabel())->toBe(__('Run EMI collection cycle'))
        ->and(ContributionCycleHeaderActions::collectOldestArrearsFirstToggle()->getDefaultState())->toBeTrue();
});

test('emi collection summary export includes pending period installments', function () {
    [$month, $year] = $this->cycles->currentOpenPeriod();
    [$start] = $this->cycles->cycleDueDateBounds($month, $year);
    $dueDate = Carbon::parse($start)->addDays(5);

    $member = Member::create([
        'member_number' => 'EMI-EXP-1',
        'name' => 'Export Borrower',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);
    $member->cashAccount->update(['balance' => 200]);

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
        'due_date' => $dueDate,
        'status' => 'pending',
    ]);

    $response = app(EmiCollectionSummaryExportService::class)->downloadCsv($month, $year);
    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    expect($csv)->toContain('EMI-EXP-1')
        ->and($csv)->toContain('Export Borrower')
        ->and($csv)->toContain('1000.00')
        ->and($csv)->toContain('800.00');
});

test('run emi collection cycle collects from cash for open period', function () {
    [$month, $year] = $this->cycles->currentOpenPeriod();
    [$start] = $this->cycles->cycleDueDateBounds($month, $year);
    $dueDate = Carbon::parse($start)->addDays(5);

    $member = Member::create([
        'member_number' => 'EMI-RUN-1',
        'name' => 'Run Borrower',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);
    $member->cashAccount->update(['balance' => 1500]);

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
        'due_date' => $dueDate,
        'status' => 'pending',
    ]);

    $results = app(LoanEmiCollectionCatalogService::class)->applyInstallmentsForPeriod($month, $year);

    expect($results['applied']->pluck('id')->all())->toContain($member->id)
        ->and($installment->fresh()->status)->toBe('paid');
});

test('send emi due notifications notifies borrowers with unpaid period installments', function () {
    Notification::fake();

    [$month, $year] = $this->cycles->currentOpenPeriod();
    [$start] = $this->cycles->cycleDueDateBounds($month, $year);
    $dueDate = Carbon::parse($start)->addDays(5);

    $user = User::create([
        'name' => 'Due Borrower',
        'email' => 'emi-due@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $member = Member::create([
        'user_id' => $user->id,
        'member_number' => 'EMI-DUE-1',
        'name' => 'Due Borrower',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

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
        'due_date' => $dueDate,
        'status' => 'pending',
    ]);

    expect(app(LoanRepaymentService::class)->sendDueNotifications($month, $year))->toBe(1);

    Notification::assertSentTo($user, LoanRepaymentDueNotification::class);
});
