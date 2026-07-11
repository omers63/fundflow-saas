<?php

declare(strict_types=1);

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\AccountingService;
use App\Support\BusinessDaySettings;
use App\Support\ContributionExemptionPolicy;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    $this->policy = app(ContributionExemptionPolicy::class);
    $this->accounting = app(AccountingService::class);
    Setting::set('contribution', 'cycle_start_day', '6');
    BusinessDaySettings::saveFromForm(null);
});

afterEach(function () {
    BusinessDaySettings::saveFromForm(null);
    Carbon::setTestNow();
});

function createPolicyMember(AccountingService $accounting, array $overrides = []): Member
{
    $member = Member::create(array_merge([
        'member_number' => 'POL-'.uniqid(),
        'name' => 'Policy Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2021-01-01'),
        'status' => 'active',
    ], $overrides));

    $accounting->createMemberAccounts($member);

    return $member->fresh();
}

test('grace cycle labels replay exactly N periods from disbursement', function () {
    $member = createPolicyMember($this->accounting);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 10_000,
        'amount_requested' => 10_000,
        'amount_approved' => 10_000,
        'amount_disbursed' => 10_000,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'status' => 'active',
        'grace_cycles' => 2,
        'has_grace_cycle' => true,
        'disbursed_at' => Carbon::parse('2025-09-21'),
        'applied_at' => Carbon::parse('2025-09-21'),
    ]);

    $labels = $this->policy->graceCycleLabels($loan->fresh());

    expect($labels)->toHaveCount(2)
        ->and($this->policy->isLoanInGraceCycle($loan, 9, 2025))->toBeTrue()
        ->and($this->policy->isLoanInGraceCycle($loan, 10, 2025))->toBeTrue()
        ->and($this->policy->isLoanInGraceCycle($loan, 11, 2025))->toBeFalse();
});

test('gap month between grace and first repayment is contribution liable', function () {
    $member = createPolicyMember($this->accounting);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 10_000,
        'amount_requested' => 10_000,
        'amount_approved' => 10_000,
        'amount_disbursed' => 10_000,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'status' => 'active',
        'grace_cycles' => 1,
        'has_grace_cycle' => true,
        'disbursed_at' => Carbon::parse('2025-09-21'),
        'applied_at' => Carbon::parse('2025-09-21'),
        'first_repayment_month' => 11,
        'first_repayment_year' => 2025,
    ]);

    expect($this->policy->isLoanInGraceCycle($loan, 9, 2025))->toBeTrue()
        ->and($this->policy->isLoanInEmiRepaymentPhase($loan, 10, 2025))->toBeFalse()
        ->and($this->policy->isLoanInEmiRepaymentPhase($loan, 11, 2025))->toBeTrue()
        ->and($this->policy->isContributionExemptForCycle($member, 10, 2025))->toBeFalse();
});

test('emi exemption starts at first repayment not disbursement month', function () {
    $member = createPolicyMember($this->accounting);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 10_000,
        'amount_requested' => 10_000,
        'amount_approved' => 10_000,
        'amount_disbursed' => 10_000,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'status' => 'active',
        'grace_cycles' => 0,
        'disbursed_at' => Carbon::parse('2025-05-01'),
        'applied_at' => Carbon::parse('2025-05-01'),
        'first_repayment_month' => 7,
        'first_repayment_year' => 2025,
    ]);

    expect($this->policy->isLoanInEmiRepaymentPhase($loan, 5, 2025))->toBeFalse()
        ->and($this->policy->isLoanInEmiRepaymentPhase($loan, 6, 2025))->toBeFalse()
        ->and($this->policy->isLoanInEmiRepaymentPhase($loan, 7, 2025))->toBeTrue();
});

test('period less exempt uses open cycle not any pending installment', function () {
    BusinessDaySettings::saveFromForm('2025-06-15');
    Carbon::setTestNow(Carbon::parse('2025-06-15'));
    Setting::set('contribution', 'cycle_start_day', '6');

    $member = createPolicyMember($this->accounting);

    Loan::create([
        'member_id' => $member->id,
        'amount' => 10_000,
        'amount_requested' => 10_000,
        'amount_approved' => 10_000,
        'amount_disbursed' => 10_000,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'status' => 'active',
        'grace_cycles' => 0,
        'disbursed_at' => Carbon::parse('2025-05-01'),
        'applied_at' => Carbon::parse('2025-05-01'),
        'first_repayment_month' => 6,
        'first_repayment_year' => 2025,
    ]);

    expect($member->fresh()->isExemptFromContributions())->toBeTrue()
        ->and($member->fresh()->isExemptFromContributions(5, 2025))->toBeFalse()
        ->and($member->fresh()->isExemptFromContributions(6, 2025))->toBeTrue();

    Carbon::setTestNow();
});

test('grace shifts forward when member already contributed for disbursement cycle', function () {
    $member = createPolicyMember($this->accounting);

    Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate(9, 2025),
        'amount' => 500,
        'status' => 'posted',
        'posted_at' => Carbon::parse('2025-09-15'),
        'created_at' => Carbon::parse('2025-09-15'),
        'payment_method' => 'cash_account',
    ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 10_000,
        'amount_requested' => 10_000,
        'amount_approved' => 10_000,
        'amount_disbursed' => 10_000,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'status' => 'active',
        'grace_cycles' => 1,
        'has_grace_cycle' => true,
        'disbursed_at' => Carbon::parse('2025-09-21'),
        'applied_at' => Carbon::parse('2025-09-21'),
    ]);

    expect($this->policy->isLoanInGraceCycle($loan->fresh(), 9, 2025))->toBeFalse()
        ->and($this->policy->isLoanInGraceCycle($loan->fresh(), 10, 2025))->toBeTrue();
});

test('transferred loan has no grace cycles for guarantor', function () {
    $guarantor = createPolicyMember($this->accounting, ['member_number' => 'G-'.uniqid()]);

    $loan = Loan::create([
        'member_id' => $guarantor->id,
        'amount' => 10_000,
        'amount_requested' => 10_000,
        'amount_approved' => 10_000,
        'amount_disbursed' => 10_000,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'status' => 'transferred',
        'grace_cycles' => 2,
        'has_grace_cycle' => true,
        'disbursed_at' => Carbon::parse('2025-05-01'),
        'applied_at' => Carbon::parse('2025-05-01'),
        'first_repayment_month' => 8,
        'first_repayment_year' => 2025,
        'transferred_to_guarantor_at' => Carbon::parse('2025-07-01'),
    ]);

    expect($this->policy->graceCycleLabels($loan))->toBe([]);
});
