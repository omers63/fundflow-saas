<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\AccountingService;
use App\Services\Loans\LoanManualScheduleGracePushService;
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
    BusinessDaySettings::saveFromForm('2025-02-05');

    $this->accounting = app(AccountingService::class);
    $this->pushes = app(LoanManualScheduleGracePushService::class);
    $this->policy = app(LoanRepaymentWindowPolicy::class);
});

afterEach(function () {
    BusinessDaySettings::saveFromForm(null);
});

function createMemberForManualGracePush(AccountingService $accounting): Member
{
    $member = Member::create([
        'member_number' => 'MEM-'.uniqid(),
        'name' => 'Manual Grace Push Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);

    return $member->fresh();
}

/**
 * Loan whose first repayment is Jan 2025 with two unpaid EMIs (Jan + Feb).
 */
function createLoanForManualGracePush(Member $member, int $graceCycles = 0): Loan
{
    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 3000,
        'amount_requested' => 3000,
        'amount_approved' => 3000,
        'amount_disbursed' => 3000,
        'interest_rate' => 0,
        'term_months' => 2,
        'monthly_repayment' => 1500,
        'installments_count' => 2,
        'total_repaid' => 0,
        'status' => 'active',
        'grace_cycles' => $graceCycles,
        'has_grace_cycle' => $graceCycles > 0,
        'exempted_month' => $graceCycles > 0 ? 12 : null,
        'exempted_year' => $graceCycles > 0 ? 2024 : null,
        'first_repayment_month' => 1,
        'first_repayment_year' => 2025,
        'applied_at' => Carbon::create(2024, 12, 1),
        'disbursed_at' => Carbon::create(2024, 12, 10),
    ]);

    $policy = app(LoanRepaymentWindowPolicy::class);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1500,
        'due_date' => $policy->installmentDueDateForCycle(1, 2025),
        'status' => 'pending',
    ]);
    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 2,
        'amount' => 1500,
        'due_date' => $policy->installmentDueDateForCycle(2, 2025),
        'status' => 'pending',
    ]);

    return $loan->fresh();
}

test('pushes unpaid schedule one cycle and marks previous first repayment as grace', function () {
    $member = createMemberForManualGracePush($this->accounting);
    $loan = createLoanForManualGracePush($member);

    $stats = $this->pushes->push((int) $loan->id);

    $loan->refresh();
    $first = $loan->installments()->where('installment_number', 1)->first();
    $second = $loan->installments()->where('installment_number', 2)->first();

    expect($stats['shifted'])->toBeTrue()
        ->and($stats['cycles'])->toBe(1)
        ->and($stats['installments'])->toBe(2)
        ->and((int) $loan->grace_cycles)->toBe(1)
        ->and($loan->has_grace_cycle)->toBeTrue()
        ->and((int) $loan->exempted_month)->toBe(1)
        ->and((int) $loan->exempted_year)->toBe(2025)
        ->and((int) $loan->first_repayment_month)->toBe(2)
        ->and((int) $loan->first_repayment_year)->toBe(2025)
        ->and($first->due_date->toDateString())->toBe(
            $this->policy->installmentDueDateForCycle(2, 2025)->toDateString()
        )
        ->and($second->due_date->toDateString())->toBe(
            $this->policy->installmentDueDateForCycle(3, 2025)->toDateString()
        );
});

test('increments existing grace and advances the exempted end month', function () {
    $member = createMemberForManualGracePush($this->accounting);
    $loan = createLoanForManualGracePush($member, graceCycles: 1);

    $this->pushes->push((int) $loan->id, cycles: 1);

    $loan->refresh();

    expect((int) $loan->grace_cycles)->toBe(2)
        ->and((int) $loan->exempted_month)->toBe(1)
        ->and((int) $loan->exempted_year)->toBe(2025)
        ->and((int) $loan->first_repayment_month)->toBe(2)
        ->and((int) $loan->first_repayment_year)->toBe(2025);
});

test('shifts paid and unpaid installments together so the schedule leaves the grace cycle', function () {
    $member = createMemberForManualGracePush($this->accounting);
    $loan = createLoanForManualGracePush($member);

    $loan->installments()->where('installment_number', 1)->update([
        'status' => 'paid',
        'paid_at' => Carbon::create(2025, 1, 10),
    ]);

    $this->pushes->push((int) $loan->id);

    $loan->refresh();
    $paid = $loan->installments()->where('installment_number', 1)->first();
    $unpaid = $loan->installments()->where('installment_number', 2)->first();

    expect($paid->status)->toBe('paid')
        ->and($paid->due_date->toDateString())->toBe(
            $this->policy->installmentDueDateForCycle(2, 2025)->toDateString()
        )
        ->and($unpaid->due_date->toDateString())->toBe(
            $this->policy->installmentDueDateForCycle(3, 2025)->toDateString()
        )
        ->and((int) $loan->first_repayment_month)->toBe(2)
        ->and((int) $loan->exempted_month)->toBe(1);
});

test('dry run does not persist changes', function () {
    $member = createMemberForManualGracePush($this->accounting);
    $loan = createLoanForManualGracePush($member);

    $stats = $this->pushes->push((int) $loan->id, dryRun: true);

    expect($stats['dry_run'])->toBeTrue()
        ->and($stats['new_first_repayment'])->toBe('2/2025')
        ->and((int) $loan->fresh()->grace_cycles)->toBe(0)
        ->and($loan->fresh()->installments()->where('status', 'pending')->count())->toBe(2);
});

test('rejects missing loan and loans without installments', function () {
    $member = createMemberForManualGracePush($this->accounting);
    $loan = createLoanForManualGracePush($member);
    $loan->installments()->delete();

    expect(fn () => $this->pushes->push(999999999))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->pushes->push((int) $loan->id))
        ->toThrow(InvalidArgumentException::class);
});

test('artisan command requires force, supports loan id, and is registered for jobs ui', function () {
    $member = createMemberForManualGracePush($this->accounting);
    $loan = createLoanForManualGracePush($member);

    expect(Artisan::call('loans:push-schedule-grace'))->toBe(0)
        ->and((int) $loan->fresh()->grace_cycles)->toBe(0);

    expect(Artisan::call('loans:push-schedule-grace', [
        '--force' => true,
        '--loan' => $loan->id,
    ]))->toBe(0)
        ->and((int) $loan->fresh()->grace_cycles)->toBe(1)
        ->and((int) $loan->fresh()->first_repayment_month)->toBe(2);

    $registry = collect(ScheduledJobRegistry::all())
        ->firstWhere('key', 'loans:push-schedule-grace');

    expect($registry)->not->toBeNull()
        ->and($registry['schedule'])->toBe(__('Manual only (one-time)'))
        ->and(collect(ScheduledJobRegistry::all())->where('key', 'loans:shift-single-overdue-grace'))->toBeEmpty();
});
