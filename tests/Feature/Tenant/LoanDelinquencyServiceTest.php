<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanDelinquencyService;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    $this->delinquency = app(LoanDelinquencyService::class);
    $this->cycles = app(ContributionCycleService::class);

    Account::query()->delete();
    Member::query()->delete();
    Loan::query()->delete();
    LoanInstallment::query()->delete();
    Contribution::query()->delete();
});

function createMemberForDelinquency(AccountingService $accounting, array $overrides = []): Member
{
    $member = Member::create(array_merge([
        'member_number' => 'MEM-'.uniqid(),
        'name' => 'Delinquency Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ], $overrides));

    $accounting->createMemberAccounts($member);
    $member->cashAccount()->update(['balance' => 50000]);
    $member->fundAccount()->update(['balance' => 50000]);

    return $member->fresh();
}

test('pending installment past cycle deadline is marked overdue', function () {
    $member = createMemberForDelinquency(app(AccountingService::class));
    $guarantor = createMemberForDelinquency(app(AccountingService::class), ['member_number' => 'G-'.uniqid(), 'name' => 'Guarantor']);

    $due = now()->subMonths(2)->startOfMonth();

    $loan = Loan::create([
        'member_id' => $member->id,
        'guarantor_member_id' => $guarantor->id,
        'amount' => 10000,
        'amount_requested' => 10000,
        'amount_approved' => 10000,
        'amount_disbursed' => 10000,
        'interest_rate' => 10,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => now()->subMonths(3),
        'disbursed_at' => now()->subMonths(3),
    ]);

    $installment = LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => $due,
        'status' => 'pending',
    ]);

    expect($installment->status)->toBe('pending');

    $marked = $this->delinquency->markOverdueInstallments();

    $installment->refresh();
    expect($marked)->toBe(1)
        ->and($installment->status)->toBe('overdue')
        ->and($installment->is_late)->toBeTrue();
});

test('member with overdue installments is marked delinquent', function () {
    $member = createMemberForDelinquency(app(AccountingService::class));

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 5000,
        'amount_requested' => 5000,
        'amount_approved' => 5000,
        'amount_disbursed' => 5000,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => now()->subMonths(2),
        'disbursed_at' => now()->subMonths(2),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => now()->subMonth(),
        'status' => 'overdue',
    ]);

    $result = $this->delinquency->syncMemberDelinquencyStatus();

    $member->refresh();
    expect($result['marked_delinquent'])->toBe(1)
        ->and($member->status)->toBe('delinquent');
});

test('guarantor liability can be transferred when installments are overdue', function () {
    $member = createMemberForDelinquency(app(AccountingService::class));
    $guarantor = createMemberForDelinquency(app(AccountingService::class), ['member_number' => 'G2-'.uniqid()]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'guarantor_member_id' => $guarantor->id,
        'amount' => 8000,
        'amount_requested' => 8000,
        'amount_approved' => 8000,
        'amount_disbursed' => 8000,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => now()->subMonths(2),
        'disbursed_at' => now()->subMonths(2),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => now()->subMonth(),
        'status' => 'overdue',
    ]);

    $this->delinquency->transferGuarantorLiability($loan->fresh());

    expect($loan->fresh()->guarantor_liability_transferred_at)->not->toBeNull();
});

test('unpaid contribution periods are listed after deadline', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 20));

    $member = createMemberForDelinquency(app(AccountingService::class));
    [$m, $y] = $this->cycles->currentOpenPeriod();

    $prev = Carbon::create($y, $m, 1)->subMonthNoOverflow();
    $prevM = (int) $prev->month;
    $prevY = (int) $prev->year;
    $label = $this->cycles->periodLabel($prevM, $prevY);

    expect($this->delinquency->unpaidContributionPeriodLabels($member))->toContain($label);

    Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate($prevM, $prevY),
        'amount' => 5000,
        'status' => 'posted',
        'posted_at' => now(),
        'payment_method' => Contribution::PAYMENT_METHOD_ADMIN,
    ]);

    expect($this->delinquency->unpaidContributionPeriodLabels($member->fresh()))
        ->not->toContain($label);

    Carbon::setTestNow();
});

test('contribution arrears exclude periods before member joined', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 20));

    $member = createMemberForDelinquency(app(AccountingService::class), [
        'joined_at' => Carbon::create(2026, 4, 15),
    ]);

    [$m, $y] = $this->cycles->currentOpenPeriod();
    $prev = Carbon::create($y, $m, 1)->subMonthNoOverflow();
    $prevM = (int) $prev->month;
    $prevY = (int) $prev->year;

    $labels = $this->delinquency->unpaidContributionPeriodLabels($member);

    $beforeJoin = Carbon::create(2026, 3, 1);
    $beforeLabel = $this->cycles->periodLabel((int) $beforeJoin->month, (int) $beforeJoin->year);

    expect($labels)->not->toContain($beforeLabel);

    $prevLabel = $this->cycles->periodLabel($prevM, $prevY);
    expect($labels)->toContain($prevLabel);

    Carbon::setTestNow();
});

test('contribution arrears table has one row per unpaid period with status', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 20));

    $member = createMemberForDelinquency(app(AccountingService::class));

    [$m, $y] = $this->cycles->currentOpenPeriod();
    $prev = Carbon::create($y, $m, 1)->subMonthNoOverflow();
    $prevM = (int) $prev->month;
    $prevY = (int) $prev->year;

    Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate($prevM, $prevY),
        'amount' => 5000,
        'status' => 'pending',
        'payment_method' => Contribution::PAYMENT_METHOD_ADMIN,
    ]);

    $rows = $this->delinquency->contributionArrearsTableRecords();
    $periodLabel = $this->cycles->periodLabel($prevM, $prevY);

    $row = $rows->first(
        fn (array $r): bool => $r['member_id'] === $member->id && $r['period_label'] === $periodLabel
    );

    expect($row)->not->toBeNull()
        ->and($row['contribution_status'])->toBe('pending');

    Carbon::setTestNow();
});

test('contribution arrears records can be filtered by member', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 20));

    $memberA = createMemberForDelinquency(app(AccountingService::class), [
        'member_number' => 'A-'.uniqid(),
        'name' => 'Member Alpha',
    ]);
    $memberB = createMemberForDelinquency(app(AccountingService::class), [
        'member_number' => 'B-'.uniqid(),
        'name' => 'Member Beta',
    ]);

    $all = $this->delinquency->contributionArrearsTableRecords();
    expect($all->pluck('member_id')->unique()->count())->toBeGreaterThanOrEqual(2);

    $filtered = $this->delinquency->filterContributionArrearsRecords(
        $all,
        memberId: $memberA->id,
    );

    expect($filtered)->not->toBeEmpty()
        ->and($filtered->every(fn (array $row): bool => $row['member_id'] === $memberA->id))->toBeTrue()
        ->and($filtered->contains('member_id', $memberB->id))->toBeFalse();

    $scoped = $this->delinquency->contributionArrearsTableRecords($memberA->id);

    expect($scoped->every(fn (array $row): bool => $row['member_id'] === $memberA->id))->toBeTrue();

    Carbon::setTestNow();
});
