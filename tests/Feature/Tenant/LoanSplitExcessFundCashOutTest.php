<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Loans\Pages\EditLoan;
use App\Models\Tenant\Account;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanDisbursement;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanLedgerService;
use App\Services\Loans\LoanLifecycleService;
use App\Services\Loans\LoanSplitExcessFundCashOutService;
use App\Services\LoanService;
use App\Support\BusinessDay;
use App\Support\ContributionCollectionStatus;
use App\Support\LoanFundingStrategy;
use App\Support\LoanSettings;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    Account::query()->delete();
    Member::query()->delete();
    Loan::query()->delete();
    CashOutRequest::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 200_000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 200_000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    FundTier::query()->update(['percentage' => 100]);

    $this->accounting = app(AccountingService::class);
    $this->lifecycle = app(LoanLifecycleService::class);
    $this->loanService = app(LoanService::class);
    $this->cashOutService = app(LoanSplitExcessFundCashOutService::class);

    $this->actingAs(User::create([
        'name' => 'Split Excess Admin',
        'email' => 'split-excess-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]), 'tenant');
});

function createSplitExcessMember(AccountingService $accounting, float $fundBalance = 15_000): Member
{
    $member = Member::create([
        'member_number' => 'MEM-'.uniqid(),
        'name' => 'Split Excess Member',
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

test('split loan without disbursement cash-out offers post-disbursement excess cash-out', function () {
    LoanSettings::save(['member_funding_split_pct' => 50]);

    $member = createSplitExcessMember($this->accounting, 15_000);

    $loan = $this->lifecycle->applyForLoan(
        $member,
        10_000,
        'Keep excess',
        fundingStrategy: LoanFundingStrategy::SPLIT_PERCENTAGE,
        cashOutExcessFund: false,
    );
    $this->loanService->approveLoan($loan, 10_000);
    $this->loanService->disburseLoan($loan);

    $loan = $loan->fresh();

    expect($this->cashOutService->offersCashOut($loan))->toBeTrue()
        ->and($this->cashOutService->disbursementExcessAmount($loan))->toBe(10_000.0)
        ->and($this->cashOutService->remainingEligibleAmount($loan))->toBe(10_000.0)
        ->and($this->cashOutService->maxTransferableAmount($loan))->toBe(10_000.0)
        ->and((float) $loan->member_fund_balance_at_disbursement)->toBe(15_000.0);
});

test('post-disbursement split excess cash-out transfers fund to cash and auto-accepts cash-out request', function () {
    LoanSettings::save(['member_funding_split_pct' => 50]);

    $member = createSplitExcessMember($this->accounting, 15_000);

    $loan = $this->lifecycle->applyForLoan(
        $member,
        10_000,
        'Keep excess',
        fundingStrategy: LoanFundingStrategy::SPLIT_PERCENTAGE,
        cashOutExcessFund: false,
    );
    $this->loanService->approveLoan($loan, 10_000);
    $this->loanService->disburseLoan($loan);

    $loan = $loan->fresh();
    $member = $member->fresh();

    expect($member->getFundBalance())->toBe(5000.0)
        ->and($member->getCashBalance())->toBe(10_000.0);

    $request = $this->cashOutService->cashOut($loan, 3_000.0, 'Partial excess payout');

    $member = $member->fresh();
    $loan = $loan->fresh();

    expect($request->status)->toBe('accepted')
        ->and((float) $request->amount)->toBe(3000.0)
        ->and($member->getFundBalance())->toBe(2000.0)
        ->and($member->getCashBalance())->toBe(10_000.0)
        ->and($this->cashOutService->alreadyTransferredAmount($loan))->toBe(3000.0)
        ->and($this->cashOutService->remainingEligibleAmount($loan))->toBe(7000.0)
        ->and($this->cashOutService->maxTransferableAmount($loan))->toBe(7000.0);
});

test('split loan that already cashed excess at disbursement does not offer post-disbursement cash-out', function () {
    LoanSettings::save(['member_funding_split_pct' => 50]);

    $member = createSplitExcessMember($this->accounting, 15_000);

    $loan = $this->lifecycle->applyForLoan(
        $member,
        10_000,
        'Cash excess',
        fundingStrategy: LoanFundingStrategy::SPLIT_PERCENTAGE,
        cashOutExcessFund: true,
    );
    $this->loanService->approveLoan($loan, 10_000);
    $this->loanService->disburseLoan($loan);

    expect($this->cashOutService->offersCashOut($loan->fresh()))->toBeFalse();
});

test('post-disbursement split excess cash-out can use a custom date on or after disbursement', function () {
    LoanSettings::save(['member_funding_split_pct' => 50]);

    $member = createSplitExcessMember($this->accounting, 15_000);

    $loan = $this->lifecycle->applyForLoan(
        $member,
        10_000,
        'Keep excess',
        fundingStrategy: LoanFundingStrategy::SPLIT_PERCENTAGE,
        cashOutExcessFund: false,
    );
    $this->loanService->approveLoan($loan, 10_000);

    $disbursedAt = Carbon::parse('2024-03-15 10:00:00');
    $this->lifecycle->disbursePartial($loan->fresh(), 10_000, null, $disbursedAt);

    $loan = $loan->fresh();
    $cashOutAt = Carbon::parse('2024-04-01 14:30:00');

    $request = $this->cashOutService->cashOut(
        $loan,
        2_000.0,
        'Backdated excess payout',
        transactedAt: $cashOutAt,
    );

    $fundDebit = Transaction::query()
        ->where('reference_type', Loan::class)
        ->where('reference_id', $loan->id)
        ->where('type', 'debit')
        ->whereHas('account', fn ($query) => $query->where('member_id', $loan->member_id)->where('type', 'fund'))
        ->where('description', 'like', '%'.__('Loan #:id — excess fund to cash', ['id' => $loan->id]).'%')
        ->latest('id')
        ->first();

    expect($request->status)->toBe('accepted')
        ->and($request->reviewed_at?->toDateTimeString())->toBe($cashOutAt->toDateTimeString())
        ->and($fundDebit?->transacted_at?->toDateTimeString())->toBe($cashOutAt->toDateTimeString());
});

test('split excess cash-out rejects a date before loan disbursement', function () {
    LoanSettings::save(['member_funding_split_pct' => 50]);

    $member = createSplitExcessMember($this->accounting, 15_000);

    $loan = $this->lifecycle->applyForLoan(
        $member,
        10_000,
        'Keep excess',
        fundingStrategy: LoanFundingStrategy::SPLIT_PERCENTAGE,
        cashOutExcessFund: false,
    );
    $this->loanService->approveLoan($loan, 10_000);
    $this->lifecycle->disbursePartial($loan->fresh(), 10_000, null, Carbon::parse('2024-03-15'));

    $loan = $loan->fresh();

    expect(fn () => $this->cashOutService->cashOut(
        $loan,
        1_000.0,
        transactedAt: Carbon::parse('2024-03-14'),
    ))->toThrow(InvalidArgumentException::class, __('Cash-out date must be on or after the loan disbursement date (:date).', [
        'date' => '2024-03-15',
    ]));
});

test('split excess fund reconstructs pre-disbursement balance from ledger for legacy loans', function () {
    LoanSettings::save(['member_funding_split_pct' => 50]);

    $member = Member::create([
        'member_number' => 'LEG-RECON',
        'name' => 'Legacy Reconstruction Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYears(10),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);
    Account::masterCash()->update(['balance' => 500_000]);
    Account::masterFund()->update(['balance' => 500_000]);

    $disbursedAt = Carbon::parse('2018-07-31');

    AccountingService::withoutMemberCashCollection(
        fn () => $this->accounting->creditMemberFundWithMasterMirror(
            $member->fundAccount,
            195_250,
            'Legacy fund buildup',
            '',
            null,
            Carbon::parse('2018-07-01'),
            $member->id,
        ),
    );

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 300_000,
        'amount_requested' => 300_000,
        'amount_approved' => 300_000,
        'amount_disbursed' => 300_000,
        'interest_rate' => 0,
        'term_months' => 24,
        'monthly_repayment' => 12_500,
        'total_repaid' => 0,
        'status' => 'active',
        'funding_strategy' => LoanFundingStrategy::SPLIT_PERCENTAGE,
        'cash_out_excess_fund' => false,
        'member_portion' => 150_000,
        'master_portion' => 150_000,
        'member_fund_balance_at_disbursement' => null,
        'disbursed_at' => $disbursedAt,
        'purpose' => 'Legacy imported loan',
    ]);

    $ledger = app(LoanLedgerService::class);
    $member = $member->fresh();

    $ledger->postPartialLoanDisbursement(
        $loan,
        300_000,
        LoanDisbursement::create([
            'loan_id' => $loan->id,
            'amount' => 300_000,
            'member_portion' => 0,
            'master_portion' => 0,
            'disbursed_at' => $disbursedAt,
        ]),
        $disbursedAt,
        allowNegativeMasterFundBalance: true,
        memberFundBalanceAtDisbursement: 195_250,
    );

    AccountingService::withoutMemberCashCollection(
        fn () => $this->accounting->creditMemberFundWithMasterMirror(
            $member->fresh()->fundAccount,
            50_000,
            'Later repayment credit',
            '',
            null,
            Carbon::parse('2020-01-01'),
            $member->id,
        ),
    );

    $loan = $loan->fresh();

    expect($this->cashOutService->summary($loan)['fund_balance_at_disbursement'])->toBe(195_250.0)
        ->and($this->cashOutService->disbursementExcessAmount($loan))->toBe(45_250.0)
        ->and($this->cashOutService->remainingEligibleAmount($loan))->toBe(45_250.0);
});

test('post-disbursement split excess cash-out allows payout when current fund is negative', function () {
    LoanSettings::save(['member_funding_split_pct' => 50]);

    $member = Member::create([
        'member_number' => 'NEG-FUND',
        'name' => 'Negative Fund Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYears(10),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);
    Account::masterCash()->update(['balance' => 500_000]);
    Account::masterFund()->update(['balance' => 500_000]);

    $disbursedAt = Carbon::parse('2018-07-31');

    AccountingService::withoutMemberCashCollection(
        fn () => $this->accounting->creditMemberFundWithMasterMirror(
            $member->fundAccount,
            195_250,
            'Legacy fund buildup',
            '',
            null,
            Carbon::parse('2018-07-01'),
            $member->id,
        ),
    );

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 300_000,
        'amount_requested' => 300_000,
        'amount_approved' => 300_000,
        'amount_disbursed' => 300_000,
        'interest_rate' => 0,
        'term_months' => 24,
        'monthly_repayment' => 12_500,
        'total_repaid' => 0,
        'status' => 'active',
        'funding_strategy' => LoanFundingStrategy::SPLIT_PERCENTAGE,
        'cash_out_excess_fund' => false,
        'member_portion' => 150_000,
        'master_portion' => 150_000,
        'member_fund_balance_at_disbursement' => null,
        'disbursed_at' => $disbursedAt,
        'purpose' => 'Legacy imported loan',
    ]);

    $ledger = app(LoanLedgerService::class);

    $ledger->postPartialLoanDisbursement(
        $loan,
        300_000,
        LoanDisbursement::create([
            'loan_id' => $loan->id,
            'amount' => 300_000,
            'member_portion' => 0,
            'master_portion' => 0,
            'disbursed_at' => $disbursedAt,
        ]),
        $disbursedAt,
        allowNegativeMasterFundBalance: true,
        memberFundBalanceAtDisbursement: 195_250,
    );

    AccountingService::withoutMemberCashCollection(
        fn () => $this->accounting->creditMemberFundWithMasterMirror(
            $member->fresh()->fundAccount,
            98_250,
            'Later repayment credits',
            '',
            null,
            Carbon::parse('2020-01-01'),
            $member->id,
        ),
    );

    $loan = $loan->fresh();
    $member = $member->fresh();

    expect($member->getFundBalance())->toBe(-6_500.0)
        ->and($this->cashOutService->remainingEligibleAmount($loan))->toBe(45_250.0)
        ->and($this->cashOutService->maxTransferableAmount($loan))->toBe(45_250.0)
        ->and($this->cashOutService->summary($loan)['fund_shortfall'])->toBe(45_250.0);

    $request = $this->cashOutService->cashOut($loan, 45_250.0, 'Historical excess payout');

    $member = $member->fresh();

    expect($request->status)->toBe('accepted')
        ->and((float) $request->amount)->toBe(45_250.0)
        ->and($member->getFundBalance())->toBe(-51_750.0)
        ->and($this->cashOutService->remainingEligibleAmount($loan->fresh()))->toBe(0.0);
});

test('active split loan edit page shows cash out split excess fund action when eligible', function () {
    LoanSettings::save(['member_funding_split_pct' => 50]);

    $member = createSplitExcessMember($this->accounting, 15_000);

    $loan = $this->lifecycle->applyForLoan(
        $member,
        10_000,
        'Keep excess',
        fundingStrategy: LoanFundingStrategy::SPLIT_PERCENTAGE,
        cashOutExcessFund: false,
    );
    $this->loanService->approveLoan($loan, 10_000);
    $this->loanService->disburseLoan($loan);

    Livewire::test(EditLoan::class, ['record' => $loan->fresh()->getKey()])
        ->assertSuccessful()
        ->assertActionVisible('cashOutSplitExcessFund');
});

test('split excess fund cash-out action refreshes edit page without error', function () {
    LoanSettings::save(['member_funding_split_pct' => 50]);

    $member = createSplitExcessMember($this->accounting, 15_000);

    $loan = $this->lifecycle->applyForLoan(
        $member,
        10_000,
        'Keep excess',
        fundingStrategy: LoanFundingStrategy::SPLIT_PERCENTAGE,
        cashOutExcessFund: false,
    );
    $this->loanService->approveLoan($loan, 10_000);
    $this->loanService->disburseLoan($loan);

    $loan = $loan->fresh();

    Livewire::test(EditLoan::class, ['record' => $loan->getKey()])
        ->assertSuccessful()
        ->mountAction('cashOutSplitExcessFund')
        ->setActionData([
            'amount' => 2_000,
            'cashed_out_at' => BusinessDay::now()->format('Y-m-d H:i:s'),
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($this->cashOutService->alreadyTransferredAmount($loan->fresh()))->toBe(2_000.0);
});
