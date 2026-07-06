<?php

declare(strict_types=1);

use App\Filament\Member\Resources\MyDependents\Support\DependentOpenCycleStatus;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Support\BusinessDaySettings;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    BusinessDaySettings::saveFromForm('2026-06-15');

    Member::query()->delete();
    Loan::query()->delete();
    LoanInstallment::query()->delete();
    Contribution::query()->delete();
});

afterEach(function () {
    BusinessDaySettings::saveFromForm(null);
    Carbon::setTestNow();
});

test('open cycle status shows contribution only when no EMI is due', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $member = Member::factory()->create([
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);

    Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate(6, 2026),
        'amount' => 500,
        'status' => 'pending',
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
    ]);

    $status = DependentOpenCycleStatus::resolve($member->fresh(), 6, 2026);

    expect($status['label'])->toBe(__('Pending'))
        ->and($status['color'])->toBe('warning')
        ->and($status['description'])->toBeNull();

    Carbon::setTestNow();
});

test('open cycle status shows EMI and exempt contribution for loan repayment cycle', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $member = Member::factory()->create([
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    app(AccountingService::class)->createMemberAccounts($member);

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
        'applied_at' => Carbon::parse('2026-01-01'),
        'disbursed_at' => Carbon::parse('2026-01-01'),
    ]);

    foreach ([
        ['month' => 6, 'year' => 2026, 'number' => 1],
        ['month' => 7, 'year' => 2026, 'number' => 2],
    ] as $period) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $period['number'],
            'amount' => 1000,
            'due_date' => Carbon::create($period['year'], $period['month'], 15),
            'status' => 'pending',
        ]);
    }

    [$openMonth, $openYear] = app(ContributionCycleService::class)->currentOpenPeriod();
    $status = DependentOpenCycleStatus::resolve($member->fresh(), $openMonth, $openYear);

    expect($member->fresh()->isExemptFromContributions($openMonth, $openYear))->toBeTrue()
        ->and($status['label'])->toBe(__('EMI: :status', ['status' => __('Pending')]))
        ->and($status['color'])->toBe('warning')
        ->and($status['description'])->toBe(__('Contribution: :status', ['status' => __('Exempt')]));

    Carbon::setTestNow();
});
