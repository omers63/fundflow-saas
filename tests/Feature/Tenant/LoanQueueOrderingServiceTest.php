<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Services\Loans\LoanQueueOrderingService;
use App\Services\Loans\LoanQueueService;
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
    $this->fundTier->update(['percentage' => 100, 'is_active' => true]);
    $loanTier->update(['fund_tier_id' => $this->fundTier->id]);
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
        'loan_tier_id' => $fundTier->loanTiers()->value('id'),
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

test('remapping a loan tier moves queued and active loans onto the new fund pool', function () {
    $loanTierId = (int) $this->fundTier->loanTiers()->value('id');

    $fundB = FundTier::query()->create([
        'tier_number' => FundTier::nextTierNumber(),
        'label' => 'Fund Tier B',
        'percentage' => 40,
        'is_active' => true,
    ]);

    $approved = makeQueueOrderingLoan($this->member, $this->fundTier, [
        'queue_position' => 1,
    ]);
    $partial = makeQueueOrderingLoan($this->member, $this->fundTier, [
        'status' => 'partially_disbursed',
        'amount_disbursed' => 2500,
        'queue_position' => 2,
    ]);
    $active = makeQueueOrderingLoan($this->member, $this->fundTier, [
        'status' => 'active',
        'amount_disbursed' => 10_000,
        'queue_position' => 3,
    ]);
    $emergency = makeQueueOrderingLoan($this->member, $this->fundTier, [
        'is_emergency' => true,
        'queue_position' => 4,
    ]);
    $pending = makeQueueOrderingLoan($this->member, $this->fundTier, [
        'status' => 'pending',
        'amount_approved' => null,
        'fund_tier_id' => null,
        'queue_position' => null,
    ]);

    $this->fundTier->syncLoanTiers([]);
    $fundB->syncLoanTiers([$loanTierId]);

    expect($approved->fresh()->fund_tier_id)->toBe($fundB->id)
        ->and($partial->fresh()->fund_tier_id)->toBe($fundB->id)
        ->and($active->fresh()->fund_tier_id)->toBe($fundB->id)
        ->and($emergency->fresh()->fund_tier_id)->toBe($this->fundTier->id)
        ->and($pending->fresh()->fund_tier_id)->toBeNull()
        ->and((int) $approved->fresh()->queue_position)->toBe(1)
        ->and((int) $partial->fresh()->queue_position)->toBe(2)
        ->and((int) $active->fresh()->queue_position)->toBe(3)
        ->and(Loan::query()->where('fund_tier_id', $this->fundTier->id)->whereKeyNot($emergency->id)->whereIn('status', ['approved', 'partially_disbursed', 'active'])->count())->toBe(0);
});

test('editing a loan tier fund pool realigns stamped loan fund tiers', function () {
    $loanTier = LoanTier::query()->findOrFail($this->fundTier->loanTiers()->value('id'));
    $fundB = FundTier::query()->create([
        'tier_number' => FundTier::nextTierNumber(),
        'label' => 'Pool B',
        'percentage' => 25,
        'is_active' => true,
    ]);

    $loan = makeQueueOrderingLoan($this->member, $this->fundTier, [
        'queue_position' => 1,
    ]);

    $previousFundTierId = (int) $loanTier->fund_tier_id;
    $loanTier->update(['fund_tier_id' => $fundB->id]);
    $resequenced = LoanQueueOrderingService::realignLoansToCurrentFundMapping([(int) $loanTier->id]);
    LoanQueueOrderingService::resequenceFundTier($previousFundTierId);

    expect($loan->fresh()->fund_tier_id)->toBe($fundB->id)
        ->and($resequenced)->toContain($fundB->id)
        ->and($resequenced)->toContain($previousFundTierId);
});

test('deleting a fund tier reassigns active loans onto the band current pool', function () {
    $loanTier = LoanTier::query()->findOrFail($this->fundTier->loanTiers()->value('id'));

    $survivor = FundTier::query()->create([
        'tier_number' => FundTier::nextTierNumber(),
        'label' => 'Survivor pool',
        'percentage' => 50,
        'is_active' => true,
    ]);

    $doomed = FundTier::query()->create([
        'tier_number' => FundTier::nextTierNumber(),
        'label' => 'Doomed pool',
        'percentage' => 25,
        'is_active' => true,
    ]);

    $loanTier->update(['fund_tier_id' => $survivor->id]);

    $active = makeQueueOrderingLoan($this->member, $this->fundTier, [
        'status' => 'active',
        'amount_disbursed' => 10_000,
        'fund_tier_id' => $doomed->id,
        'loan_tier_id' => $loanTier->id,
    ]);
    $queued = makeQueueOrderingLoan($this->member, $this->fundTier, [
        'status' => 'approved',
        'fund_tier_id' => $doomed->id,
        'loan_tier_id' => $loanTier->id,
    ]);

    expect($doomed->delete())->toBeTrue()
        ->and($doomed->fresh()->trashed())->toBeTrue()
        ->and($active->fresh()->fund_tier_id)->toBe($survivor->id)
        ->and($queued->fresh()->fund_tier_id)->toBe($survivor->id);

    $running = app(LoanQueueService::class)->tierQueues();
    $survivorCard = collect($running)->first(
        fn (array $card): bool => (int) $card['tier']->id === (int) $survivor->id,
    );

    expect($survivorCard)->not->toBeNull()
        ->and(collect($survivorCard['running'])->pluck('loan.id')->all())->toContain($active->id)
        ->and(collect($survivorCard['loans'])->pluck('loan.id')->all())->toContain($queued->id);
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
