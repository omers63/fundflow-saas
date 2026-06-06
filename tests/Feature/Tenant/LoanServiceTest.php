<?php

use App\Filament\Tenant\Clusters\LoanQueuePage;
use App\Filament\Tenant\Clusters\LoansCluster;
use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanLifecycleService;
use App\Services\LoanService;
use App\Support\ContributionCollectionStatus;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    $this->accounting = app(AccountingService::class);
    $this->service = app(LoanService::class);

    Account::query()->delete();
    Member::query()->delete();
    Loan::query()->delete();
    LoanInstallment::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);
});

function createEligibleLoanMember(AccountingService $accounting, float $fundBalance = 15000): Member
{
    $member = Member::create([
        'member_number' => 'MEM-'.uniqid(),
        'name' => 'Test Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);
    $member->fundAccount()->update(['balance' => $fundBalance]);
    $member->cashAccount()->update(['balance' => $fundBalance]);

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

test('member with less than 12 months is not eligible', function () {
    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'New Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(6),
        'status' => 'active',
    ]);

    $result = $this->service->checkEligibility($member);

    expect($result['eligible'])->toBeFalse();
});

test('suspended member is not eligible', function () {
    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'Suspended Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'suspended',
    ]);

    $result = $this->service->checkEligibility($member);

    expect($result['eligible'])->toBeFalse();
});

test('member with active loan is not eligible', function () {
    $member = createEligibleLoanMember($this->accounting);

    Loan::create([
        'member_id' => $member->id,
        'amount' => 10000,
        'amount_requested' => 10000,
        'interest_rate' => 10,
        'term_months' => 12,
        'monthly_repayment' => 0,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => now(),
        'amount_approved' => 10000,
        'amount_disbursed' => 10000,
    ]);

    $result = $this->service->checkEligibility($member);

    expect($result['eligible'])->toBeFalse();
});

test('eligible member passes checks when fund balance is sufficient', function () {
    $member = createEligibleLoanMember($this->accounting, 20000);

    expect((float) $member->fundAccount->balance)->toBeGreaterThan(6000);

    $result = $this->service->checkEligibility($member);

    expect($result['eligible'])->toBeTrue();
});

test('loan application creates a pending loan with amount requested', function () {
    $member = createEligibleLoanMember($this->accounting, 25000);

    $loan = $this->service->applyForLoan($member, 20000, 0, 0, 'Education');

    expect($loan->status)->toBe('pending')
        ->and($loan->amount_requested)->toBe('20000.00')
        ->and($loan->purpose)->toBe('Education');
});

test('loans navigation badges show pending queue count', function () {
    $member = createEligibleLoanMember($this->accounting, 25000);

    $this->service->applyForLoan($member, 20000);

    expect(LoansCluster::getNavigationBadge())->toBe('1')
        ->and(LoansCluster::getNavigationBadgeColor())->toBe('warning')
        ->and(LoanQueuePage::getNavigationBadge())->toBe('1');
});

test('approve and full disburse activates loan with installments', function () {
    $member = createEligibleLoanMember($this->accounting, 30000);
    Account::masterFund()->update(['balance' => 100000]);
    Account::masterCash()->update(['balance' => 100000]);

    $loan = $this->service->applyForLoan($member, 20000);
    $this->service->approveLoan($loan, 20000);
    $this->service->disburseLoan($loan);

    $loan->refresh();
    expect($loan->status)->toBe('active')
        ->and($loan->isFullyDisbursed())->toBeTrue()
        ->and($loan->installments()->count())->toBeGreaterThan(0)
        ->and($loan->disbursements()->count())->toBeGreaterThan(0);
});

test('partial disbursement sets partially_disbursed status', function () {
    $member = createEligibleLoanMember($this->accounting, 30000);
    Account::masterFund()->update(['balance' => 100000]);
    Account::masterCash()->update(['balance' => 100000]);

    $loan = $this->service->applyForLoan($member, 20000);
    $this->service->approveLoan($loan, 20000);
    app(LoanLifecycleService::class)->disbursePartial($loan, 5000);

    $loan->refresh();

    expect($loan->status)->toBe('partially_disbursed')
        ->and((float) $loan->amount_disbursed)->toBe(5000.0);
});

test('pending loan can be rejected with reason', function () {
    $member = createEligibleLoanMember($this->accounting);

    $loan = $this->service->applyForLoan($member, 10000, 0, 0, 'Home repair');
    $this->service->rejectLoan($loan, 'Insufficient documentation');

    $loan->refresh();
    expect($loan->status)->toBe('rejected')
        ->and($loan->rejection_reason)->toBe('Insufficient documentation')
        ->and($loan->rejected_at)->not->toBeNull();
});

test('loan amount cannot exceed configured maximum for member', function () {
    $member = createEligibleLoanMember($this->accounting, 10000);

    expect(fn () => $this->service->applyForLoan($member, 50000))
        ->toThrow(InvalidArgumentException::class);
});
