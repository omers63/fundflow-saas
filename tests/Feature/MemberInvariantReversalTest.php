<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use App\Services\ContributionCollectionCycleService;
use App\Services\FundPostingService;
use App\Services\Loans\LoanLedgerService;
use App\Services\Loans\LoanRepaymentService;
use App\Services\MemberInvariantService;
use App\Support\BusinessDaySettings;
use App\Support\ContributionCollectionStatus;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function (): void {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    Notification::fake();

    Account::query()->delete();
    Member::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 100_000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 100_000, 'is_master' => true]);

    $this->accounting = app(AccountingService::class);
    $this->invariants = app(MemberInvariantService::class);

    BusinessDaySettings::saveFromForm('2026-02-20');
    Carbon::setTestNow('2026-02-20 12:00:00');
});

afterEach(function (): void {
    BusinessDaySettings::saveFromForm(null);
    Carbon::setTestNow();
});

function invariantMember(AccountingService $accounting, float $cash, array $overrides = []): Member
{
    $member = Member::create(array_merge([
        'member_number' => 'INV-REV-'.uniqid(),
        'name' => 'Invariant Reversal Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
        'opening_cash_balance' => 0,
        'opening_fund_balance' => 0,
    ], $overrides));

    $accounting->createMemberAccounts($member);
    $member->refresh();
    $member->update(['opening_cash_balance' => $cash]);
    $member->cashAccount?->update(['balance' => $cash]);

    return $member->fresh();
}

test('member invariants stay balanced after reversing a collected contribution', function (): void {
    $member = invariantMember($this->accounting, 800);

    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate(2, 2026),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PENDING,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
    ]);

    expect(app(ContributionCollectionCycleService::class)->attemptCollection($contribution))->toBe('collected');
    expect($this->invariants->check($member->fresh())['balanced'])->toBeTrue();

    AccountingService::withoutMemberCashCollection(
        fn () => $this->accounting->reverseAllSourceEntries($contribution, 'Window rollback'),
    );

    $check = $this->invariants->check($member->fresh());

    expect($check['balanced'])->toBeTrue()
        ->and($check['cash_drift'])->toBe(0.0)
        ->and($check['fund_drift'])->toBe(0.0)
        ->and($check['actual_cash'])->toBe(800.0)
        ->and($check['expected_cash'])->toBe(800.0);
});

test('member invariants stay balanced after reversing a collected EMI', function (): void {
    $member = invariantMember($this->accounting, 2000, ['monthly_contribution_amount' => 0]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 12_000,
        'amount_requested' => 12_000,
        'amount_approved' => 12_000,
        'amount_disbursed' => 12_000,
        'member_portion' => 12_000,
        'master_portion' => 12_000,
        'settlement_threshold' => 0,
        'installments_count' => 12,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2026-01-01'),
        'disbursed_at' => Carbon::parse('2026-01-01'),
        'first_repayment_month' => 2,
        'first_repayment_year' => 2026,
    ]);

    $installment = LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::parse('2026-02-15'),
        'status' => 'pending',
    ]);

    expect(app(LoanRepaymentService::class)->applyOpenPeriodRepaymentForMember($member->fresh()))->toBe('applied');
    expect($this->invariants->check($member->fresh())['balanced'])->toBeTrue();

    app(LoanLedgerService::class)->reverseInstallmentPosting($installment->fresh(), 'Window rollback');

    $check = $this->invariants->check($member->fresh());

    expect($check['balanced'])->toBeTrue()
        ->and($check['cash_drift'])->toBe(0.0)
        ->and($check['fund_drift'])->toBe(0.0)
        ->and($check['actual_cash'])->toBe(2000.0)
        ->and($check['expected_cash'])->toBe(2000.0);
});

test('member cash invariant stays balanced after reversing a deposit and dependent transfer', function (): void {
    $parent = invariantMember($this->accounting, 100, ['monthly_contribution_amount' => 0, 'name' => 'Parent Member']);
    $dependent = invariantMember($this->accounting, 0, [
        'monthly_contribution_amount' => 0,
        'name' => 'Dependent Member',
        'parent_member_id' => $parent->id,
    ]);

    $posting = app(FundPostingService::class)->submit($parent->fresh(), 2500, '2026-02-20');
    AccountingService::withoutMemberCashCollection(
        fn () => app(FundPostingService::class)->accept($posting),
    );

    AccountingService::withoutMemberCashCollection(function () use ($parent, $dependent): void {
        app(AccountingService::class)->fundDependentCashAccount(
            $parent->fresh(),
            $dependent->fresh(),
            500,
            'Allocation — November 2025',
        );
    });

    expect($this->invariants->check($parent->fresh())['balanced'])->toBeTrue()
        ->and($this->invariants->check($dependent->fresh())['balanced'])->toBeTrue();

    $depositLine = Transaction::query()
        ->where('account_id', $parent->fresh()->cashAccount->id)
        ->where('reference_type', FundPosting::class)
        ->where('reference_id', $posting->id)
        ->first();

    $out = Transaction::query()
        ->where('account_id', $parent->fresh()->cashAccount->id)
        ->where('type', 'debit')
        ->where(function ($query): void {
            $query->where('description', 'like', 'Transfer to%')
                ->orWhere('description', 'like', 'تحويل إلى%');
        })
        ->first();
    $in = Transaction::query()
        ->where('account_id', $dependent->fresh()->cashAccount->id)
        ->where('type', 'credit')
        ->where(function ($query): void {
            $query->where('description', 'like', 'Transfer from%')
                ->orWhere('description', 'like', 'تحويل من%');
        })
        ->first();

    expect($out)->not->toBeNull()
        ->and($in)->not->toBeNull();

    AccountingService::withoutMemberCashCollection(function () use ($depositLine, $out, $in): void {
        $accounting = app(AccountingService::class);
        $accounting->createReversalEntry($out, 'Window rollback');
        $accounting->createReversalEntry($in, 'Window rollback');
        $accounting->createReversalEntry($depositLine, 'Window rollback');
    });

    expect($this->invariants->check($parent->fresh())['balanced'])->toBeTrue()
        ->and($this->invariants->check($parent->fresh())['cash_drift'])->toBe(0.0)
        ->and($this->invariants->check($dependent->fresh())['balanced'])->toBeTrue()
        ->and($this->invariants->check($dependent->fresh())['cash_drift'])->toBe(0.0);
});
