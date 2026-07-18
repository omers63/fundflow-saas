<?php

declare(strict_types=1);

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\MonthlyStatementService;
use App\Support\BusinessDaySettings;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    $this->memberUser = User::create([
        'name' => 'Statement Business Day',
        'email' => 'statement-bizday@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-STMT-BD',
        'name' => 'Statement Business Day',
        'email' => 'statement-bizday@fund.test',
        'phone' => '0500000099',
        'monthly_contribution_amount' => 1000,
        'joined_at' => Carbon::parse('2025-01-01'),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);
    BusinessDaySettings::saveFromForm('2026-05-15');
});

afterEach(function () {
    BusinessDaySettings::saveFromForm(null);
});

test('statement activity yearly lifetime and closing balances clamp to business day', function () {
    $cash = $this->member->cashAccount;
    $fund = $this->member->fundAccount;

    // Insert ledger rows directly so cash-increase auto-collection does not rewrite the timeline.
    Transaction::query()->create([
        'account_id' => $cash->id,
        'member_id' => $this->member->id,
        'type' => 'credit',
        'amount' => 500,
        'balance_after' => 500,
        'description' => 'Before cutoff deposit',
        'transacted_at' => '2026-05-10 12:00:00',
    ]);
    Transaction::query()->create([
        'account_id' => $cash->id,
        'member_id' => $this->member->id,
        'type' => 'credit',
        'amount' => 800,
        'balance_after' => 1300,
        'description' => 'After cutoff deposit',
        'transacted_at' => '2026-05-20 12:00:00',
    ]);
    Transaction::query()->create([
        'account_id' => $fund->id,
        'member_id' => $this->member->id,
        'type' => 'credit',
        'amount' => 900,
        'balance_after' => 900,
        'description' => 'Opening fund balance',
        'transacted_at' => '2026-04-30 12:00:00',
    ]);
    Transaction::query()->create([
        'account_id' => $fund->id,
        'member_id' => $this->member->id,
        'type' => 'debit',
        'amount' => 200,
        'balance_after' => 700,
        'description' => 'Fund movement before cutoff',
        'transacted_at' => '2026-05-10 12:00:00',
    ]);
    Transaction::query()->create([
        'account_id' => $fund->id,
        'member_id' => $this->member->id,
        'type' => 'credit',
        'amount' => 500,
        'balance_after' => 1200,
        'description' => 'Fund movement after cutoff',
        'transacted_at' => '2026-05-20 12:00:00',
    ]);

    Contribution::query()->create([
        'member_id' => $this->member->id,
        'period' => Contribution::periodDate(4, 2026),
        'amount' => 1000,
        'status' => 'posted',
        'paid_at' => '2026-04-08',
    ]);
    Contribution::query()->create([
        'member_id' => $this->member->id,
        'period' => Contribution::periodDate(5, 2026),
        'amount' => 1000,
        'status' => 'posted',
        'paid_at' => '2026-05-10',
    ]);

    $tier = LoanTier::query()->create([
        'tier_number' => 91,
        'label' => 'BizDay Tier',
        'min_amount' => 1000,
        'max_amount' => 50000,
        'min_monthly_installment' => 500,
        'is_active' => true,
    ]);

    $loan = Loan::query()->create([
        'member_id' => $this->member->id,
        'loan_tier_id' => $tier->id,
        'amount' => 10000,
        'amount_requested' => 10000,
        'amount_approved' => 10000,
        'amount_disbursed' => 10000,
        'interest_rate' => 0,
        'term_months' => 10,
        'monthly_repayment' => 1000,
        'total_repaid' => 2000,
        'status' => 'active',
        'applied_at' => '2026-01-01',
        'approved_at' => '2026-01-05',
        'disbursed_at' => '2026-01-10',
    ]);

    LoanInstallment::query()->create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 400,
        'due_date' => '2026-05-01',
        'paid_at' => '2026-05-10',
        'status' => 'paid',
    ]);
    LoanInstallment::query()->create([
        'loan_id' => $loan->id,
        'installment_number' => 2,
        'amount' => 600,
        'due_date' => '2026-05-01',
        'paid_at' => '2026-05-20',
        'status' => 'paid',
    ]);

    LoanRepayment::query()->create([
        'loan_id' => $loan->id,
        'amount' => 400,
        'paid_at' => '2026-05-10',
    ]);
    LoanRepayment::query()->create([
        'loan_id' => $loan->id,
        'amount' => 600,
        'paid_at' => '2026-05-20',
    ]);

    $statement = app(MonthlyStatementService::class)->generateForMember($this->member, '2026-05');
    $details = $statement->details;

    expect($details['as_of'])->toBe('2026-05-15')
        ->and((float) $statement->opening_balance)->toEqual(900.0)
        ->and((float) $statement->closing_balance)->toEqual(700.0)
        ->and($details['fund_opening'])->toEqual(900.0)
        ->and($details['fund_closing'])->toEqual(700.0)
        ->and($details['total_contributions'])->toEqual(1000.0)
        ->and($details['total_repayments'])->toEqual(400.0)
        ->and($details['cash_closing'])->toEqual(500.0)
        ->and($details['lifetime']['total_contributions'])->toEqual(2000.0)
        ->and($details['lifetime']['total_repayments'])->toEqual(400.0)
        ->and($details['lifetime']['collection_total'])->toEqual(2400.0)
        ->and($details['current_year_totals']['to_period'])->toBe('2026-05')
        ->and($details['current_year_totals']['from_period'])->toBe('2025-12');

    $mayActivity = collect($details['current_year_months'])->firstWhere('period', '2026-05');

    expect($mayActivity)->not->toBeNull()
        ->and($mayActivity['contributions'])->toEqual(1000.0)
        ->and($mayActivity['repayments'])->toEqual(400.0)
        ->and($mayActivity['contribution_dates'])->toBe(['2026-05-10'])
        ->and($mayActivity['repayment_dates'])->toBe(['2026-05-10']);

    $year2026 = collect($details['yearly_history'])->firstWhere('year', 2026);

    expect($year2026)->not->toBeNull()
        ->and($year2026['contributions'])->toEqual(2000.0)
        ->and($year2026['repayments'])->toEqual(400.0)
        ->and($year2026['cash_balance'])->toEqual(500.0)
        ->and(collect($details['period_transactions'])->pluck('amount')->map(fn ($amount) => (float) $amount)->all())
        ->toEqual([500.0, 200.0]);
});

test('period contribution paid after business day is excluded from statement totals', function () {
    Contribution::query()->create([
        'member_id' => $this->member->id,
        'period' => Contribution::periodDate(5, 2026),
        'amount' => 1000,
        'status' => 'posted',
        'paid_at' => '2026-05-20',
    ]);

    $statement = app(MonthlyStatementService::class)->generateForMember($this->member, '2026-05');
    $details = $statement->details;
    $mayActivity = collect($details['current_year_months'])->firstWhere('period', '2026-05');

    expect($details['total_contributions'])->toEqual(0.0)
        ->and($details['lifetime']['total_contributions'])->toEqual(0.0)
        ->and($mayActivity['contributions'])->toEqual(0.0);
});

test('six-month activity and yearly history stop at business-day month when statement period is later', function () {
    Contribution::query()->create([
        'member_id' => $this->member->id,
        'period' => Contribution::periodDate(5, 2026),
        'amount' => 1000,
        'status' => 'posted',
        'paid_at' => '2026-05-10',
    ]);
    Contribution::query()->create([
        'member_id' => $this->member->id,
        'period' => Contribution::periodDate(6, 2026),
        'amount' => 1000,
        'status' => 'posted',
        'paid_at' => '2026-06-05',
    ]);

    $statement = app(MonthlyStatementService::class)->generateForMember($this->member, '2026-06');
    $details = $statement->details;

    expect($details['as_of'])->toBe('2026-05-15')
        ->and($details['current_year_totals']['to_period'])->toBe('2026-05')
        ->and($details['current_year_totals']['from_period'])->toBe('2025-12')
        ->and(collect($details['current_year_months'])->pluck('period')->all())->not->toContain('2026-06')
        ->and($details['total_contributions'])->toEqual(0.0);

    $year2026 = collect($details['yearly_history'])->firstWhere('year', 2026);

    expect($year2026['contributions'])->toEqual(1000.0)
        ->and(collect($details['yearly_history'])->max('year'))->toBe(2026);
});
