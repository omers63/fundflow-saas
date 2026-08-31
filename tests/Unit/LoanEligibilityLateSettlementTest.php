<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\AccountingService;
use App\Services\Loans\LoanEligibilityService;
use App\Support\ContributionCollectionStatus;
use App\Support\LoanEligibilityGate;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    Contribution::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    Setting::set('delinquency', 'late_settlement_consecutive_threshold', '3');
    Setting::set('loan', 'late_payment_consecutive_threshold', '99');
});

test('late contribution settlements block new loan applications using contribution thresholds', function () {
    Carbon::setTestNow(Carbon::create(2026, 6, 15));

    $member = Member::create([
        'member_number' => 'MEM-ELIG-LATE-'.uniqid(),
        'name' => 'Late Contribution Eligible',
        'monthly_contribution_amount' => 1000,
        'joined_at' => Carbon::create(2026, 3, 1),
        'status' => 'active',
    ]);
    app(AccountingService::class)->createMemberAccounts($member);
    $member->fundAccount()->update(['balance' => 50_000]);

    foreach ([3, 4, 5] as $month) {
        Contribution::create([
            'member_id' => $member->id,
            'period' => Contribution::periodDate($month, 2026),
            'amount' => 1000,
            'amount_due' => 1000,
            'amount_collected' => 1000,
            'status' => 'posted',
            'collection_status' => ContributionCollectionStatus::COLLECTED,
            'posted_at' => Carbon::create(2026, $month, 20),
            'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
            'is_late' => true,
        ]);
    }

    app()->setLocale('en');

    $eligibility = app(LoanEligibilityService::class);
    $failed = $eligibility->getFailedGates($member);

    expect($eligibility->isEligible($member))->toBeFalse()
        ->and($failed)->toHaveKey(LoanEligibilityGate::DELINQUENCY)
        ->and($failed[LoanEligibilityGate::DELINQUENCY])->toContain('late contribution');

    Carbon::setTestNow();
});
