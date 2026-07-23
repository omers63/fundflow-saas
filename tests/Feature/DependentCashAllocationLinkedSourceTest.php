<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\DependentCashAllocation;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Support\BusinessDaySettings;
use App\Support\ContributionCollectionStatus;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    BusinessDaySettings::saveFromForm(null);

    Account::query()->delete();
    Contribution::query()->delete();
    DependentCashAllocation::query()->delete();
    Member::query()->delete();
    Transaction::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);

    $this->accounting = app(AccountingService::class);
    $this->cycles = app(ContributionCycleService::class);
});

test('dependent cash allocation ledger legs link DependentCashAllocation as source', function () {
    [$month, $year] = $this->cycles->currentOpenPeriod();

    $parent = Member::create([
        'member_number' => 'MEM-ALLOC-P',
        'name' => 'Allocation Parent',
        'email' => 'alloc-parent@fund.test',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($parent);

    $dependent = Member::create([
        'member_number' => 'MEM-ALLOC-D',
        'name' => 'Allocation Dependent',
        'email' => 'alloc-dep@fund.test',
        'parent_member_id' => $parent->id,
        'household_email' => 'alloc-parent@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($dependent);

    Contribution::create([
        'member_id' => $dependent->id,
        'period' => Contribution::periodDate($month, $year),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PENDING,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
    ]);

    AccountingService::withoutMemberCashCollection(
        fn () => $this->accounting->credit($parent->cashAccount, 500, 'Parent seed'),
    );

    $result = $this->cycles->applyDependentAllocationForParentForPeriod($parent->fresh(), $month, $year);

    expect($result['transfers'])->toBe(1);

    $allocation = DependentCashAllocation::query()
        ->where('dependent_member_id', $dependent->id)
        ->where('allocation_month', $month)
        ->where('allocation_year', $year)
        ->first();

    expect($allocation)->not->toBeNull();

    $linked = Transaction::query()
        ->where('reference_type', DependentCashAllocation::class)
        ->where('reference_id', $allocation->id)
        ->get();

    expect($linked)->toHaveCount(4)
        ->and($linked->every(fn (Transaction $tx): bool => $tx->hasLinkedReference()))->toBeTrue()
        ->and($linked->every(fn (Transaction $tx): bool => $tx->linkedSourceLabel() === __('Dependent allocation #:id', ['id' => $allocation->id])))->toBeTrue()
        ->and($linked->where('type', 'debit')->count())->toBe(2)
        ->and($linked->where('type', 'credit')->count())->toBe(2);
});
