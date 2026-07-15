<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Services\Loans\LoanQueueOrderingService;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    Loan::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 100_000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $this->member = Member::create([
        'member_number' => 'MEM-QUEUE01',
        'name' => 'Queue Test Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $loanTier = LoanTier::query()->firstOrCreate(
        ['tier_number' => 0],
        ['label' => 'Loan Tier 0', 'min_amount' => 0, 'max_amount' => 50_000, 'min_monthly_installment' => 0, 'is_active' => true],
    );

    $this->fundTier = FundTier::query()->firstOrCreate(
        ['tier_number' => 1],
        ['label' => 'Fund Tier 1'],
    );
    $this->fundTier->update(['loan_tier_id' => $loanTier->id, 'percentage' => 100, 'is_active' => true]);
});

function makeQueueOrderingLoan(Member $member, FundTier $fundTier, array $overrides = []): Loan
{
    return Loan::query()->create(array_merge([
        'member_id' => $member->id,
        'amount' => 10_000,
        'amount_requested' => 10_000,
        'amount_approved' => 10_000,
        'amount_disbursed' => 0,
        'interest_rate' => 0,
        'term_months' => 10,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'approved',
        'is_emergency' => false,
        'applied_at' => now()->subDays(5),
        'approved_at' => now()->subDay(),
        'loan_tier_id' => $fundTier->loan_tier_id,
        'fund_tier_id' => $fundTier->id,
    ], $overrides));
}

test('tier queue puts emergency loans first in application order', function () {
    $laterEmergency = makeQueueOrderingLoan($this->member, $this->fundTier, [
        'is_emergency' => true,
        'applied_at' => now()->subDays(1),
    ]);
    $earlierEmergency = makeQueueOrderingLoan($this->member, $this->fundTier, [
        'is_emergency' => true,
        'applied_at' => now()->subDays(3),
    ]);
    $standard = makeQueueOrderingLoan($this->member, $this->fundTier, [
        'applied_at' => now()->subDays(10),
    ]);

    $ordered = LoanQueueOrderingService::orderTierQueue(
        Loan::query()->with('loanTier')->get(),
        $this->fundTier->fresh(),
    );

    expect($ordered->pluck('id')->all())
        ->toBe([$earlierEmergency->id, $laterEmergency->id, $standard->id]);
});

test('loans that fit the disbursable pool surface ahead of oversized requests', function () {
    Account::masterFund()->update(['balance' => 5000]);

    $oversized = makeQueueOrderingLoan($this->member, $this->fundTier, [
        'amount_requested' => 8000,
        'amount_approved' => 8000,
        'applied_at' => now()->subDays(4),
    ]);
    $fits = makeQueueOrderingLoan($this->member, $this->fundTier, [
        'amount_requested' => 3000,
        'amount_approved' => 3000,
        'applied_at' => now()->subDays(2),
    ]);

    $ordered = LoanQueueOrderingService::orderTierQueue(
        Loan::query()->with('loanTier')->get(),
        $this->fundTier->fresh(),
    );

    expect($ordered->pluck('id')->all())->toBe([$fits->id, $oversized->id]);
});

test('resequence assigns queue positions to partially disbursed loans', function () {
    $approved = makeQueueOrderingLoan($this->member, $this->fundTier, [
        'applied_at' => now()->subDays(3),
    ]);
    $partial = makeQueueOrderingLoan($this->member, $this->fundTier, [
        'status' => 'partially_disbursed',
        'amount_disbursed' => 4000,
        'applied_at' => now()->subDays(2),
    ]);
    $active = makeQueueOrderingLoan($this->member, $this->fundTier, [
        'status' => 'active',
        'amount_disbursed' => 10_000,
        'applied_at' => now()->subDays(1),
    ]);

    LoanQueueOrderingService::resequenceFundTier($this->fundTier->id);

    expect((int) $approved->fresh()->queue_position)->toBe(1)
        ->and((int) $partial->fresh()->queue_position)->toBe(2)
        ->and((int) $active->fresh()->queue_position)->toBe(3);
});

test('fund tier availability accounts for partially disbursed remaining amounts', function () {
    makeQueueOrderingLoan($this->member, $this->fundTier, [
        'status' => 'partially_disbursed',
        'amount_disbursed' => 4000,
    ]);
    makeQueueOrderingLoan($this->member, $this->fundTier, [
        'status' => 'active',
        'amount_approved' => 20_000,
        'amount_disbursed' => 20_000,
    ]);

    $tier = $this->fundTier->fresh();

    expect($tier->active_exposure)->toBe(26_000.0)
        ->and($tier->available_amount)->toBe(74_000.0)
        ->and($tier->disbursable_pool)->toBe(80_000.0);
});
