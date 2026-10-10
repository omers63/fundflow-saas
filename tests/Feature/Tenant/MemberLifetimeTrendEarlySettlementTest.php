<?php

declare(strict_types=1);

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\MemberLifetimeTrendService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Cache::flush();
});

test('installments prepaid for cycles after an early settlement are shown where the cash arrived', function () {
    Carbon::setTestNow(Carbon::create(2026, 8, 20));

    $member = Member::create([
        'member_number' => 'TRD-'.uniqid(),
        'name' => 'Early Settled Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::create(2025, 1, 10),
        'status' => 'active',
    ]);
    app(AccountingService::class)->createMemberAccounts($member);

    $cycles = app(ContributionCycleService::class);
    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 3000,
        'amount_requested' => 3000,
        'amount_approved' => 3000,
        'amount_disbursed' => 3000,
        'interest_rate' => 0,
        'term_months' => 3,
        'monthly_repayment' => 1000,
        'total_repaid' => 3000,
        'status' => 'completed',
        'applied_at' => Carbon::create(2026, 1, 2),
        'disbursed_at' => Carbon::create(2026, 1, 10),
        'settled_at' => Carbon::create(2026, 3, 1),
    ]);

    // Three installments scheduled for later cycles, all paid on 1 March (the settlement day).
    foreach ([[1, '2026-05-05'], [2, '2026-06-05'], [3, '2026-07-05']] as [$n, $due]) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $n,
            'amount' => 1000,
            'amount_collected' => 1000,
            'due_date' => $due,
            'status' => 'paid',
            'paid_at' => Carbon::create(2026, 3, 1),
        ]);
    }

    $rows = collect(app(MemberLifetimeTrendService::class)->forMember($member->fresh()))->keyBy('key');

    [$cashMonth, $cashYear] = $cycles->cyclePeriodForDueDate(Carbon::create(2026, 3, 1));
    $cashKey = sprintf('%04d-%02d', $cashYear, $cashMonth);

    expect((float) $rows[$cashKey]['repaymentsPaid'])->toBe(3000.0);

    foreach (['2026-04', '2026-05', '2026-06'] as $laterKey) {
        expect((float) $rows[$laterKey]['repaymentsPaid'])->toBe(0.0)
            ->and((float) $rows[$laterKey]['repaymentsDue'])->toBe(0.0);
    }

    Carbon::setTestNow();
});
