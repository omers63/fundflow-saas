<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\AccountingService;
use App\Support\AutomationScheduleSettings;
use App\Support\ContributionCollectionStatus;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    Contribution::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);
});

it('skips realtime contribution settlement when auto-apply collections is disabled', function () {
    Setting::set(AutomationScheduleSettings::GROUP, 'auto_apply_collections', '0');

    $accounting = app(AccountingService::class);
    $period = now()->subMonth();
    $member = Member::create([
        'member_number' => 'MEM-AUTO-1',
        'name' => 'Auto Off',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);

    Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate((int) $period->month, (int) $period->year),
        'amount' => 1000,
        'amount_due' => 1000,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::OVERDUE,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'is_late' => true,
    ]);

    $accounting->creditMemberCashWithMasterMirror(
        $member->cashAccount,
        1000,
        'Test credit',
        '(mirror)',
        null,
        null,
        $member->id,
    );

    expect((float) $member->fresh()->getCashBalance())->toBe(1000.0)
        ->and(Contribution::query()->where('member_id', $member->id)->value('collection_status'))
        ->toBe(ContributionCollectionStatus::OVERDUE);
});

it('settles contributions from cash when auto-apply collections is enabled', function () {
    Setting::set(AutomationScheduleSettings::GROUP, 'auto_apply_collections', '1');

    $accounting = app(AccountingService::class);
    $period = now()->subMonth();
    $member = Member::create([
        'member_number' => 'MEM-AUTO-2',
        'name' => 'Auto On',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);

    Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate((int) $period->month, (int) $period->year),
        'amount' => 1000,
        'amount_due' => 1000,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::OVERDUE,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'is_late' => true,
    ]);

    $accounting->creditMemberCashWithMasterMirror(
        $member->cashAccount,
        1000,
        'Test credit',
        '(mirror)',
        null,
        null,
        $member->id,
    );

    expect((float) $member->fresh()->getCashBalance())->toBe(0.0)
        ->and(Contribution::query()->where('member_id', $member->id)->value('collection_status'))
        ->toBe(ContributionCollectionStatus::COLLECTED);
});
