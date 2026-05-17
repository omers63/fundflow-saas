<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    $this->cycles = app(ContributionCycleService::class);
    $this->accounting = app(AccountingService::class);

    Account::query()->delete();
    Member::query()->delete();
    Contribution::query()->delete();
    Loan::query()->delete();
    LoanInstallment::query()->delete();
});

test('pending members for period exclude posted contributors and pre-join members', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 20));

    [$month, $year] = $this->cycles->currentOpenPeriod();

    $eligible = Member::create([
        'member_number' => 'MEM-ELIG',
        'name' => 'Eligible Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($eligible);

    $posted = Member::create([
        'member_number' => 'MEM-POST',
        'name' => 'Posted Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($posted);

    Contribution::create([
        'member_id' => $posted->id,
        'period' => Contribution::periodDate($month, $year),
        'amount' => 5000,
        'status' => 'posted',
        'posted_at' => now(),
        'payment_method' => Contribution::PAYMENT_METHOD_ADMIN,
    ]);

    $futureJoin = Member::create([
        'member_number' => 'MEM-NEW',
        'name' => 'Future Join',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->addMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($futureJoin);

    $pendingIds = $this->cycles->pendingMembersQueryForPeriod($month, $year)->pluck('id');

    expect($pendingIds)->toContain($eligible->id)
        ->and($pendingIds)->not->toContain($posted->id)
        ->and($pendingIds)->not->toContain($futureJoin->id);

    Carbon::setTestNow();
});

test('pending members exclude loan-exempt members and non-posted contribution rows', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 20));

    [$month, $year] = $this->cycles->currentOpenPeriod();

    $exempt = Member::create([
        'member_number' => 'MEM-EX',
        'name' => 'Exempt Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($exempt);

    $loan = Loan::create([
        'member_id' => $exempt->id,
        'amount' => 10000,
        'amount_requested' => 10000,
        'amount_approved' => 10000,
        'amount_disbursed' => 10000,
        'interest_rate' => 10,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => now()->subMonths(2),
        'disbursed_at' => now()->subMonths(2),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => now()->subMonth(),
        'status' => 'pending',
    ]);

    $pendingOnly = Member::create([
        'member_number' => 'MEM-PEND',
        'name' => 'Pending Row Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($pendingOnly);

    Contribution::create([
        'member_id' => $pendingOnly->id,
        'period' => Contribution::periodDate($month, $year),
        'amount' => 5000,
        'status' => 'pending',
        'payment_method' => Contribution::PAYMENT_METHOD_ADMIN,
    ]);

    $pendingIds = $this->cycles->pendingMembersQueryForPeriod($month, $year)->pluck('id');

    expect($pendingIds)->not->toContain($exempt->id)
        ->and($pendingIds)->toContain($pendingOnly->id);

    Carbon::setTestNow();
});
