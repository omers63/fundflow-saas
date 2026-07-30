<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\Loans\LoanDefaultService;
use App\Services\MemberInvariantService;
use App\Services\ReconciliationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function (): void {
    $this->initializeTenancy();

    Account::query()->where('is_master', true)->delete();
    LoanInstallment::query()->delete();
    Loan::query()->delete();
    Member::query()->delete();
    User::query()->delete();
    ReconciliationException::query()->delete();
    Transaction::query()->delete();

    Account::query()->firstOrCreate(
        ['is_master' => true, 'type' => 'cash'],
        ['name' => 'Master Cash', 'balance' => 100_000, 'member_id' => null]
    );
    Account::query()->firstOrCreate(
        ['is_master' => true, 'type' => 'fund'],
        ['name' => 'Master Fund', 'balance' => 100_000, 'member_id' => null]
    );
});

function createGuarantorReconMember(AccountingService $accounting, array $memberOverrides = []): Member
{
    $user = User::query()->create([
        'name' => 'Tenant User '.uniqid(),
        'email' => 'user-'.uniqid().'@example.test',
        'password' => Hash::make('password'),
        'is_admin' => false,
    ]);

    $member = Member::query()->create(array_merge([
        'user_id' => $user->id,
        'member_number' => 'MEM-'.uniqid(),
        'name' => 'Member '.uniqid(),
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(12),
        'status' => 'active',
    ], $memberOverrides));

    $accounting->createMemberAccounts($member);

    return $member->fresh();
}

function invokeReconcileLoansAndEmi(): void
{
    $recon = app(ReconciliationService::class);
    $method = new ReflectionMethod($recon, 'reconcileLoansAndEmi');
    $method->setAccessible(true);
    $method->invoke($recon);
}

test('guarantor cover with cash top-up keeps borrower and guarantor invariants balanced', function (): void {
    Notification::fake();
    Setting::set('loan', 'default_grace_cycles', 1);
    Setting::set('late_fee', 'repayment_day_30', 0);

    $accounting = app(AccountingService::class);
    $borrower = createGuarantorReconMember($accounting);
    $guarantor = createGuarantorReconMember($accounting, [
        'member_number' => 'G-'.uniqid(),
        'opening_fund_balance' => 50_000,
    ]);
    $guarantor->fundAccount()->update(['balance' => 50_000]);

    $loan = Loan::query()->create([
        'member_id' => $borrower->id,
        'guarantor_member_id' => $guarantor->id,
        'amount' => 12_000,
        'amount_requested' => 12_000,
        'amount_approved' => 12_000,
        'amount_disbursed' => 12_000,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 2400,
        'total_repaid' => 0,
        'status' => 'active',
        'late_repayment_count' => 2,
        'applied_at' => now()->subMonths(2),
        'disbursed_at' => now()->subMonths(2),
    ]);

    LoanInstallment::query()->create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 2400,
        'due_date' => Carbon::now()->subMonths(4),
        'status' => 'overdue',
        'paid_by_guarantor' => false,
    ]);

    $result = app(LoanDefaultService::class)->processDefaults();

    expect($result['debited_from_guarantor'])->toBe(1);

    $borrowerCheck = app(MemberInvariantService::class)->check($borrower->fresh());
    $guarantorCheck = app(MemberInvariantService::class)->check($guarantor->fresh());

    expect($borrowerCheck['balanced'])->toBeTrue()
        ->and($borrowerCheck['components']['guarantor_topup_cash_credits'])->toBe(2400.0)
        ->and($borrowerCheck['components']['emi_debited'])->toBe(2400.0)
        ->and($guarantorCheck['balanced'])->toBeTrue()
        ->and($guarantorCheck['components']['guarantor_fund_debits'])->toBe(2400.0);
});

test('paid by guarantor with top-up does not raise GUARANTOR_BORROWER_DUPLICATE_DEBIT', function (): void {
    Notification::fake();
    Setting::set('loan', 'default_grace_cycles', 1);
    Setting::set('late_fee', 'repayment_day_30', 0);

    $accounting = app(AccountingService::class);
    $borrower = createGuarantorReconMember($accounting);
    $guarantor = createGuarantorReconMember($accounting, [
        'member_number' => 'G-'.uniqid(),
        'opening_fund_balance' => 50_000,
    ]);
    $guarantor->fundAccount()->update(['balance' => 50_000]);

    $loan = Loan::query()->create([
        'member_id' => $borrower->id,
        'guarantor_member_id' => $guarantor->id,
        'amount' => 12_000,
        'amount_requested' => 12_000,
        'amount_approved' => 12_000,
        'amount_disbursed' => 12_000,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 2400,
        'total_repaid' => 0,
        'status' => 'active',
        'late_repayment_count' => 2,
        'applied_at' => now()->subMonths(2),
        'disbursed_at' => now()->subMonths(2),
    ]);

    $installment = LoanInstallment::query()->create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 2400,
        'due_date' => Carbon::now()->subMonths(4),
        'status' => 'overdue',
        'paid_by_guarantor' => false,
    ]);

    app(LoanDefaultService::class)->processDefaults();
    expect($installment->fresh()->paid_by_guarantor)->toBeTrue();

    ReconciliationException::query()->delete();
    invokeReconcileLoansAndEmi();

    expect(ReconciliationException::query()
        ->where('exception_code', 'GUARANTOR_BORROWER_DUPLICATE_DEBIT')
        ->where('affected_entities->installment_id', $installment->id)
        ->exists())->toBeFalse();
});

test('paid by guarantor without cash top-up raises GUARANTOR_BORROWER_DUPLICATE_DEBIT', function (): void {
    $accounting = app(AccountingService::class);
    $borrower = createGuarantorReconMember($accounting);
    $guarantor = createGuarantorReconMember($accounting, [
        'member_number' => 'G-'.uniqid(),
        'opening_fund_balance' => 50_000,
    ]);
    $guarantor->fundAccount()->update(['balance' => 50_000]);
    $borrower->cashAccount()->update(['balance' => 5_000]);

    $loan = Loan::query()->create([
        'member_id' => $borrower->id,
        'guarantor_member_id' => $guarantor->id,
        'amount' => 12_000,
        'amount_requested' => 12_000,
        'amount_approved' => 12_000,
        'amount_disbursed' => 12_000,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 2400,
        'total_repaid' => 0,
        'status' => 'active',
        'late_repayment_count' => 0,
        'applied_at' => now()->subMonths(2),
        'disbursed_at' => now()->subMonths(2),
    ]);

    $installment = LoanInstallment::query()->create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 2400,
        'due_date' => Carbon::now()->subMonths(1),
        'status' => 'paid',
        'paid_at' => now(),
        'paid_by_guarantor' => true,
        'amount_collected' => 2400,
    ]);

    $at = now();

    AccountingService::withoutMasterPoolMirror(function () use ($accounting, $guarantor, $borrower, $installment, $at): void {
        $accounting->debit(
            $guarantor->fundAccount,
            2400,
            'Synthetic guarantor fund debit',
            $installment,
            $at,
            $guarantor->id,
        );
        $accounting->debit(
            $borrower->cashAccount,
            2400,
            'Synthetic borrower cash debit',
            $installment,
            $at,
            $borrower->id,
        );
    });

    $guarantor->fundAccount()->decrement('balance', 2400);
    $borrower->cashAccount()->decrement('balance', 2400);

    invokeReconcileLoansAndEmi();

    expect(ReconciliationException::query()
        ->where('exception_code', 'GUARANTOR_BORROWER_DUPLICATE_DEBIT')
        ->where('affected_entities->installment_id', $installment->id)
        ->open()
        ->exists())->toBeTrue();
});
