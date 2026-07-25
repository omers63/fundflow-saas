<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanAdminTransferService;
use App\Services\Loans\LoanGuarantorTransferService;
use App\Services\Loans\LoanLifecycleService;
use App\Services\Loans\LoanTransferPreview;
use App\Services\LoanService;
use App\Services\MasterAccountInvariantService;
use App\Support\ContributionCollectionStatus;
use App\Support\LoanFundingStrategy;
use App\Support\LoanSettings;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    Loan::query()->delete();
    LoanInstallment::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 500_000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 500_000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    FundTier::query()->update(['percentage' => 100]);

    LoanSettings::save([
        'allow_funding_strategy_member_topup' => true,
        'allow_funding_strategy_split_percentage' => true,
        'member_funding_split_pct' => 50,
        'max_active_loans' => 1,
    ]);

    $this->accounting = app(AccountingService::class);
    $this->lifecycle = app(LoanLifecycleService::class);
    $this->loanService = app(LoanService::class);
    $this->transfers = app(LoanAdminTransferService::class);
});

function createAdminTransferMember(AccountingService $accounting, float $fundBalance = 20_000, string $name = 'Member'): Member
{
    $member = Member::create([
        'member_number' => 'MEM-'.uniqid(),
        'name' => $name,
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);
    $member->fundAccount()->update(['balance' => $fundBalance]);
    $member->cashAccount()->update(['balance' => 0]);

    $cycles = app(ContributionCycleService::class);
    [$openMonth, $openYear] = $cycles->currentOpenPeriod();
    $cursor = $member->joined_at->copy()->startOfMonth();

    while ($cursor->lte(Carbon::create($openYear, $openMonth, 1)->endOfMonth())) {
        $month = (int) $cursor->month;
        $year = (int) $cursor->year;

        if ((float) $member->monthly_contribution_amount > 0 && ! $member->isExemptFromContributions($month, $year)) {
            Contribution::create([
                'member_id' => $member->id,
                'period' => Contribution::periodDate($month, $year),
                'amount' => $member->monthly_contribution_amount,
                'amount_due' => $member->monthly_contribution_amount,
                'amount_collected' => $member->monthly_contribution_amount,
                'status' => 'posted',
                'collection_status' => ContributionCollectionStatus::COLLECTED,
                'posted_at' => $cursor->copy()->endOfMonth(),
                'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
                'is_late' => false,
            ]);
        }

        $cursor->addMonthNoOverflow();
    }

    return $member->fresh()->load(['fundAccount', 'cashAccount']);
}

function syncMasterPoolBalances(): void
{
    $memberFundSum = (float) Account::query()
        ->where('is_master', false)
        ->where('type', 'fund')
        ->sum('balance');
    $memberCashSum = (float) Account::query()
        ->where('is_master', false)
        ->where('type', 'cash')
        ->sum('balance');

    Account::masterFund()?->update(['balance' => $memberFundSum]);
    Account::masterCash()?->update(['balance' => $memberCashSum]);
}

function createDisbursedLoanForTransfer(
    LoanLifecycleService $lifecycle,
    LoanService $loanService,
    Member $borrower,
    Member $guarantor,
    float $amount = 10_000,
    string $strategy = LoanFundingStrategy::SPLIT_PERCENTAGE,
): Loan {
    $loan = $lifecycle->applyForLoan(
        $borrower,
        $amount,
        'Admin transfer source',
        guarantorMemberId: $guarantor->id,
        fundingStrategy: $strategy,
    );
    $loanService->approveLoan($loan, $amount);
    $loanService->disburseLoan($loan);

    return $loan->fresh();
}

test('remaining admin transfer reassigns loan and rebuilds schedule for unpaid master', function () {
    $tierNumber = max(1, (int) LoanTier::withTrashed()->max('tier_number') + 1);
    $tier = LoanTier::create([
        'tier_number' => $tierNumber,
        'name' => 'Tier '.$tierNumber,
        'min_amount' => 1000,
        'max_amount' => 100_000,
        'min_monthly_installment' => 1000,
        'is_active' => true,
    ]);

    $borrower = createAdminTransferMember($this->accounting, 20_000, 'Borrower');
    $guarantor = createAdminTransferMember($this->accounting, 20_000, 'Guarantor');

    $loan = Loan::create([
        'member_id' => $borrower->id,
        'guarantor_member_id' => $guarantor->id,
        'loan_tier_id' => $tier->id,
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
        'due_date' => now()->addMonth(),
        'status' => 'pending',
    ]);

    $this->transfers->transfer(
        $loan->fresh(),
        $guarantor,
        LoanTransferPreview::MODE_REMAINING,
    );

    $loan = $loan->fresh();
    $pending = $loan->installments()->where('status', 'pending')->get();

    expect($loan->status)->toBe('transferred')
        ->and($loan->member_id)->toBe($guarantor->id)
        ->and($loan->original_borrower_member_id)->toBe($borrower->id)
        ->and($loan->admin_transfer_mode)->toBe(LoanTransferPreview::MODE_REMAINING)
        ->and($loan->admin_transferred_at)->not->toBeNull()
        ->and((float) $pending->sum('amount'))->toBe(8000.0)
        ->and($borrower->fresh()->status)->not->toBe('active');
});

test('remaining admin transfer to any member works without guarantor assignment', function () {
    $borrower = createAdminTransferMember($this->accounting, 20_000, 'Borrower');
    $other = createAdminTransferMember($this->accounting, 20_000, 'Other');

    $loan = createDisbursedLoanForTransfer(
        $this->lifecycle,
        $this->loanService,
        $borrower,
        createAdminTransferMember($this->accounting, 20_000, 'Guarantor'),
        10_000,
    );

    $this->transfers->transfer(
        $loan->fresh(),
        $other,
        LoanTransferPreview::MODE_REMAINING,
    );

    expect($loan->fresh()->member_id)->toBe($other->id)
        ->and($loan->fresh()->admin_transfer_mode)->toBe(LoanTransferPreview::MODE_REMAINING);
});

test('full admin transfer unwinds source and redisburses new loan on recipient', function () {
    $borrower = createAdminTransferMember($this->accounting, 20_000, 'Borrower');
    $guarantor = createAdminTransferMember($this->accounting, 20_000, 'Guarantor');
    syncMasterPoolBalances();
    $borrowerFundBefore = $borrower->getFundBalance();

    $loan = createDisbursedLoanForTransfer(
        $this->lifecycle,
        $this->loanService,
        $borrower,
        $guarantor,
        10_000,
    );

    $sourceId = $loan->id;
    $approved = (float) $loan->amount_approved;
    $borrowerCashAfterDisburse = $borrower->fresh()->getCashBalance();

    $newLoan = $this->transfers->transfer(
        $loan->fresh(),
        $guarantor,
        LoanTransferPreview::MODE_FULL,
    );

    $source = Loan::query()->find($sourceId);
    $borrower->unsetRelation('fundAccount');
    $borrower->unsetRelation('cashAccount');
    $borrower->refresh();
    $guarantor->unsetRelation('fundAccount');
    $guarantor->unsetRelation('cashAccount');
    $guarantor->refresh();

    expect($source->status)->toBe('cancelled')
        ->and($source->cancellation_reason)->toBe('ADMIN_TRANSFER_FULL_UNWIND')
        ->and($source->admin_transfer_mode)->toBe(LoanTransferPreview::MODE_FULL)
        ->and($newLoan->status)->toBe('active')
        ->and($newLoan->member_id)->toBe($guarantor->id)
        ->and($newLoan->transferred_from_loan_id)->toBe($sourceId)
        ->and((float) $newLoan->amount_approved)->toBe($approved)
        ->and($borrower->getFundBalance())->toBe($borrowerFundBefore)
        ->and($borrower->getCashBalance())->toBe($borrowerCashAfterDisburse)
        ->and($guarantor->getCashBalance())->toBe($approved)
        ->and(app(MasterAccountInvariantService::class)->check()['balanced'])->toBeTrue();
});

test('full admin transfer can fund entirely from master when recipient fund is low', function () {
    $borrower = createAdminTransferMember($this->accounting, 20_000, 'Borrower');
    $recipient = createAdminTransferMember($this->accounting, 100, 'LowFund');
    $guarantor = createAdminTransferMember($this->accounting, 20_000, 'Guarantor');
    syncMasterPoolBalances();

    $loan = createDisbursedLoanForTransfer(
        $this->lifecycle,
        $this->loanService,
        $borrower,
        $guarantor,
        10_000,
    );

    expect(fn () => $this->transfers->transfer(
        $loan->fresh(),
        $recipient,
        LoanTransferPreview::MODE_FULL,
        fundEntirelyFromMaster: false,
    ))->toThrow(InvalidArgumentException::class);

    $newLoan = $this->transfers->transfer(
        $loan->fresh(),
        $recipient,
        LoanTransferPreview::MODE_FULL,
        fundEntirelyFromMaster: true,
    );

    expect($newLoan->status)->toBe('active')
        ->and((float) $newLoan->member_portion)->toBe(0.0)
        ->and((float) $newLoan->master_portion)->toBe(10_000.0)
        ->and(app(MasterAccountInvariantService::class)->check()['cash_delta'])->toBe(0.0);
});

test('full admin transfer allows second running loan on recipient with override', function () {
    LoanSettings::save(['max_active_loans' => 1]);

    $borrower = createAdminTransferMember($this->accounting, 20_000, 'Borrower');
    $recipient = createAdminTransferMember($this->accounting, 30_000, 'Recipient');
    $guarantor = createAdminTransferMember($this->accounting, 20_000, 'Guarantor');
    syncMasterPoolBalances();

    createDisbursedLoanForTransfer(
        $this->lifecycle,
        $this->loanService,
        $recipient,
        $guarantor,
        10_000,
    );

    $loan = createDisbursedLoanForTransfer(
        $this->lifecycle,
        $this->loanService,
        $borrower,
        $guarantor,
        10_000,
    );

    expect(fn () => $this->transfers->transfer(
        $loan->fresh(),
        $recipient,
        LoanTransferPreview::MODE_FULL,
        allowSecondRunningLoan: false,
    ))->toThrow(InvalidArgumentException::class);

    $newLoan = $this->transfers->transfer(
        $loan->fresh(),
        $recipient,
        LoanTransferPreview::MODE_FULL,
        allowSecondRunningLoan: true,
    );

    $activeOnRecipient = Loan::query()
        ->where('member_id', $recipient->id)
        ->whereIn('status', ['active', 'partially_disbursed', 'transferred'])
        ->count();

    expect($newLoan->status)->toBe('active')
        ->and($activeOnRecipient)->toBe(2)
        ->and(app(MasterAccountInvariantService::class)->check()['balanced'])->toBeTrue();
});

test('delinquency guarantor transfer remains unchanged', function () {
    $tierNumber = max(1, (int) LoanTier::withTrashed()->max('tier_number') + 1);
    $tier = LoanTier::create([
        'tier_number' => $tierNumber,
        'name' => 'Tier '.$tierNumber,
        'min_amount' => 1000,
        'max_amount' => 100_000,
        'min_monthly_installment' => 1000,
        'is_active' => true,
    ]);

    $borrower = createAdminTransferMember($this->accounting, 20_000, 'Borrower');
    $guarantor = createAdminTransferMember($this->accounting, 20_000, 'Guarantor');

    $loan = Loan::create([
        'member_id' => $borrower->id,
        'guarantor_member_id' => $guarantor->id,
        'loan_tier_id' => $tier->id,
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
        'due_date' => now()->subMonth(),
        'status' => 'overdue',
    ]);
    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 2,
        'amount' => 1000,
        'due_date' => now()->addMonth(),
        'status' => 'pending',
    ]);

    app(LoanGuarantorTransferService::class)->transferToGuarantor($loan->fresh());

    $loan = $loan->fresh();

    expect($loan->status)->toBe('transferred')
        ->and($loan->member_id)->toBe($guarantor->id)
        ->and($loan->admin_transfer_mode)->toBeNull()
        ->and($loan->admin_transferred_at)->toBeNull();
});
