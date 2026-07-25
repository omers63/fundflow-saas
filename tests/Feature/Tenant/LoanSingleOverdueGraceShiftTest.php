<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanSingleOverdueGraceShiftService;
use App\Support\BusinessDaySettings;
use App\Support\LoanRepaymentWindowPolicy;
use App\Support\ScheduledJobRegistry;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    Loan::query()->delete();
    LoanInstallment::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);

    Setting::set('contribution', 'cycle_start_day', '6');
    BusinessDaySettings::saveFromForm('2025-11-05');

    $this->accounting = app(AccountingService::class);
    $this->cycles = app(ContributionCycleService::class);
    $this->shifts = app(LoanSingleOverdueGraceShiftService::class);
    $this->policy = app(LoanRepaymentWindowPolicy::class);
});

afterEach(function () {
    BusinessDaySettings::saveFromForm(null);
});

function createMemberForGraceShift(AccountingService $accounting): Member
{
    $member = Member::create([
        'member_number' => 'MEM-'.uniqid(),
        'name' => 'Grace Shift Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);

    return $member->fresh();
}

function createSingleOverdueLoan(Member $member, int $graceCycles = 0): Loan
{
    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 6000,
        'amount_requested' => 6000,
        'amount_approved' => 6000,
        'amount_disbursed' => 6000,
        'interest_rate' => 0,
        'term_months' => 4,
        'monthly_repayment' => 1500,
        'installments_count' => 4,
        'total_repaid' => 0,
        'status' => 'active',
        'grace_cycles' => $graceCycles,
        'has_grace_cycle' => $graceCycles > 0,
        'first_repayment_month' => 8,
        'first_repayment_year' => 2025,
        'applied_at' => Carbon::create(2025, 7, 1),
        'disbursed_at' => Carbon::create(2025, 7, 10),
    ]);

    $policy = app(LoanRepaymentWindowPolicy::class);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1500,
        'due_date' => $policy->installmentDueDateForCycle(8, 2025),
        'status' => 'paid',
        'paid_at' => Carbon::create(2025, 9, 5),
    ]);
    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 2,
        'amount' => 1500,
        'due_date' => $policy->installmentDueDateForCycle(9, 2025),
        'status' => 'paid',
        'paid_at' => Carbon::create(2025, 10, 5),
    ]);
    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 3,
        'amount' => 1500,
        'due_date' => $policy->installmentDueDateForCycle(10, 2025),
        'status' => 'overdue',
        'is_late' => true,
        'overdue_since' => Carbon::create(2025, 11, 5),
        'collection_status' => 'overdue',
    ]);
    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 4,
        'amount' => 1500,
        'due_date' => $policy->installmentDueDateForCycle(11, 2025),
        'status' => 'pending',
    ]);

    return $loan->fresh();
}

test('shifts unpaid schedule one cycle for loans with no grace and a single overdue', function () {
    $member = createMemberForGraceShift($this->accounting);
    $loan = createSingleOverdueLoan($member);

    $stats = $this->shifts->shiftEligibleLoans();

    $loan->refresh();
    $third = $loan->installments()->where('installment_number', 3)->first();
    $fourth = $loan->installments()->where('installment_number', 4)->first();
    $paid = $loan->installments()->where('installment_number', 2)->first();

    expect($stats['shifted'])->toBe(1)
        ->and($stats['installments'])->toBe(2)
        ->and((int) $loan->grace_cycles)->toBe(1)
        ->and($loan->has_grace_cycle)->toBeTrue()
        ->and((int) $loan->first_repayment_month)->toBe(9)
        ->and((int) $loan->first_repayment_year)->toBe(2025)
        ->and($third->status)->toBe('pending')
        ->and($third->due_date->toDateString())->toBe(
            $this->policy->installmentDueDateForCycle(11, 2025)->toDateString()
        )
        ->and((float) ($third->late_fee_amount ?? 0))->toBe(0.0)
        ->and($third->overdue_since)->toBeNull()
        ->and($fourth->due_date->toDateString())->toBe(
            $this->policy->installmentDueDateForCycle(12, 2025)->toDateString()
        )
        ->and($paid->due_date->toDateString())->toBe(
            $this->policy->installmentDueDateForCycle(9, 2025)->toDateString()
        );
});

test('skips loans that already have grace or more than one overdue', function () {
    $member = createMemberForGraceShift($this->accounting);
    $withGrace = createSingleOverdueLoan($member, graceCycles: 1);

    $multi = createSingleOverdueLoan(createMemberForGraceShift($this->accounting));
    $multi->installments()->where('installment_number', 4)->update(['status' => 'overdue']);

    $stats = $this->shifts->shiftEligibleLoans();

    expect($stats['shifted'])->toBe(0)
        ->and($withGrace->fresh()->grace_cycles)->toBe(1)
        ->and($multi->fresh()->installments()->where('status', 'overdue')->count())->toBe(2);
});

test('dry run does not persist changes', function () {
    $member = createMemberForGraceShift($this->accounting);
    $loan = createSingleOverdueLoan($member);

    $stats = $this->shifts->shiftEligibleLoans(dryRun: true);

    expect($stats['shifted'])->toBe(1)
        ->and($stats['dry_run'])->toBeTrue()
        ->and((int) $loan->fresh()->grace_cycles)->toBe(0)
        ->and($loan->fresh()->installments()->where('status', 'overdue')->count())->toBe(1);
});

test('shifts unpaid schedule by the requested grace cycle count', function () {
    $member = createMemberForGraceShift($this->accounting);
    $loan = createSingleOverdueLoan($member);

    $stats = $this->shifts->shiftEligibleLoans(graceCycles: 3);

    $loan->refresh();
    $third = $loan->installments()->where('installment_number', 3)->first();
    $fourth = $loan->installments()->where('installment_number', 4)->first();

    expect($stats['shifted'])->toBe(1)
        ->and($stats['grace_cycles'])->toBe(3)
        ->and((int) $loan->grace_cycles)->toBe(3)
        ->and((int) $loan->first_repayment_month)->toBe(11)
        ->and((int) $loan->first_repayment_year)->toBe(2025)
        ->and($third->status)->toBe('pending')
        ->and($third->due_date->toDateString())->toBe(
            $this->policy->installmentDueDateForCycle(1, 2026)->toDateString()
        )
        ->and($fourth->due_date->toDateString())->toBe(
            $this->policy->installmentDueDateForCycle(2, 2026)->toDateString()
        );
});

test('artisan command requires force and is registered for jobs ui', function () {
    $member = createMemberForGraceShift($this->accounting);
    createSingleOverdueLoan($member);

    expect(Artisan::call('loans:push-schedule-grace'))->toBe(0)
        ->and(Loan::query()->where('grace_cycles', 1)->count())->toBe(0);

    expect(Artisan::call('loans:push-schedule-grace', ['--force' => true]))->toBe(0)
        ->and(Loan::query()->where('grace_cycles', 1)->count())->toBe(1);

    $registry = collect(ScheduledJobRegistry::all())
        ->firstWhere('key', 'loans:push-schedule-grace');

    expect($registry)->not->toBeNull()
        ->and($registry['schedule'])->toBe(__('Manual only (one-time)'));
});

test('artisan bulk mode shifts eligible single-overdue loans without a loan id', function () {
    $member = createMemberForGraceShift($this->accounting);
    $loan = createSingleOverdueLoan($member);

    expect(Artisan::call('loans:push-schedule-grace', [
        '--force' => true,
        '--cycles' => 2,
    ]))->toBe(0)
        ->and((int) $loan->fresh()->grace_cycles)->toBe(2);
});
