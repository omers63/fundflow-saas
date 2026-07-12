<?php

declare(strict_types=1);

use App\Filament\Tables\Columns\Summarizers\CollectedEmiCashSum;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Member;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

test('collected emi cash sum does not aggregate schedule amount via sql', function () {
    expect(CollectedEmiCashSum::make()->getSelectStatements('loan_installments.amount'))->toBe([]);
});

test('collected emi cash sum uses actual repayment cash not schedule amount', function () {
    $member = Member::factory()->create(['status' => 'active']);

    $loan = Loan::factory()->for($member)->create([
        'status' => 'completed',
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

    LoanRepayment::create([
        'loan_id' => $loan->id,
        'amount' => 900,
        'paid_at' => Carbon::parse('2025-10-01'),
        'notes' => 'legacy-import:test',
    ]);

    $query = LoanInstallment::query()->whereKey($installment->id);

    expect(
        $query->clone()->get()->sum(fn(LoanInstallment $row): float => $row->collectedCashAmount()),
    )->toBe(900.0)
        ->and((float) $query->sum('amount'))->toBe(1000.0);
});
