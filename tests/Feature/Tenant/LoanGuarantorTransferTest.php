<?php

declare(strict_types=1);

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\Loans\LoanGuarantorTransferService;
use App\Services\Loans\LoanThresholdInstallmentWaiverService;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Loan::query()->delete();
    LoanInstallment::query()->delete();
    Member::query()->delete();
    LoanTier::query()->forceDelete();

    $tierNumber = max(1, (int) LoanTier::withTrashed()->max('tier_number') + 1);

    $this->tier = LoanTier::create([
        'tier_number' => $tierNumber,
        'name' => 'Tier '.$tierNumber,
        'min_amount' => 1000,
        'max_amount' => 10000,
        'min_monthly_installment' => 1000,
        'is_active' => true,
    ]);
});

function createGuarantorTransferFixture(AccountingService $accounting): array
{
    $borrower = Member::create([
        'member_number' => 'BOR-'.uniqid(),
        'name' => 'Borrower',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($borrower);

    $guarantor = Member::create([
        'member_number' => 'GUA-'.uniqid(),
        'name' => 'Guarantor',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($guarantor);

    return [$borrower, $guarantor];
}

test('guarantor transfer rebuilds schedule for fund remainder only excluding threshold', function () {
    [$borrower, $guarantor] = createGuarantorTransferFixture(app(AccountingService::class));

    $loan = Loan::create([
        'member_id' => $borrower->id,
        'guarantor_member_id' => $guarantor->id,
        'loan_tier_id' => $this->tier->id,
        'amount' => 20_000,
        'amount_requested' => 20_000,
        'amount_approved' => 20_000,
        'amount_disbursed' => 20_000,
        'member_portion' => 10_000,
        'master_portion' => 10_000,
        'settlement_threshold' => 0.05,
        'repaid_to_master' => 2_000,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'installments_count' => 11,
        'status' => 'active',
        'applied_at' => now()->subMonths(6),
        'disbursed_at' => now()->subMonths(6),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => now()->subMonths(2),
        'status' => 'paid',
        'paid_at' => now()->subMonths(2),
        'amount_collected' => 1000,
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 2,
        'amount' => 1000,
        'due_date' => now()->subMonth(),
        'status' => 'overdue',
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 3,
        'amount' => 1000,
        'due_date' => now()->addMonth(),
        'status' => 'pending',
    ]);

    app(LoanGuarantorTransferService::class)->transferToGuarantor($loan->fresh());

    $loan = $loan->fresh();
    $pending = $loan->installments()->where('status', 'pending')->orderBy('installment_number')->get();

    expect($loan->status)->toBe('transferred')
        ->and($loan->member_id)->toBe($guarantor->id)
        ->and($loan->original_borrower_member_id)->toBe($borrower->id)
        ->and($pending)->toHaveCount(8)
        ->and((float) $pending->sum('amount'))->toBe(8000.0)
        ->and((int) $loan->installments_count)->toBe(9);
});

test('threshold waiver is not available after loan is transferred to guarantor', function () {
    [$borrower, $guarantor] = createGuarantorTransferFixture(app(AccountingService::class));

    $loan = Loan::create([
        'member_id' => $guarantor->id,
        'original_borrower_member_id' => $borrower->id,
        'guarantor_member_id' => $guarantor->id,
        'loan_tier_id' => $this->tier->id,
        'amount' => 20_000,
        'amount_requested' => 20_000,
        'amount_approved' => 20_000,
        'amount_disbursed' => 20_000,
        'member_portion' => 10_000,
        'master_portion' => 10_000,
        'settlement_threshold' => 0.05,
        'repaid_to_master' => 10_000,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'status' => 'transferred',
        'applied_at' => now()->subMonths(6),
        'disbursed_at' => now()->subMonths(6),
        'transferred_to_guarantor_at' => now()->subDay(),
        'guarantor_liability_transferred_at' => now()->subDay(),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => now()->addMonth(),
        'status' => 'pending',
    ]);

    $waiver = app(LoanThresholdInstallmentWaiverService::class);

    expect($waiver->canWaive($loan))->toBeFalse()
        ->and($waiver->ineligibilityReason($loan))->toContain('Transferred guarantor');
});
