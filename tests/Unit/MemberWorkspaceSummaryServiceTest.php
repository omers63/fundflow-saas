<?php

declare(strict_types=1);

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\MemberWorkspaceSummaryService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    Member::query()->delete();
});

test('member workspace summary includes balances cycle and links without delinquency suffix', function () {
    $member = Member::factory()->create([
        'name' => 'Workspace Member',
        'status' => 'active',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subMonths(6),
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    $member->cashAccount?->update(['balance' => 1500]);
    $member->fundAccount?->update(['balance' => 2500]);

    $summary = app(MemberWorkspaceSummaryService::class)->summary($member->fresh(['cashAccount', 'fundAccount']));

    expect($summary)->toHaveKeys(['balances', 'cycle', 'arrears', 'loan', 'household', 'links', 'currency', 'member', 'contributions', 'monthly'])
        ->and($summary['balances']['cash']['amount'])->toBe(1500.0)
        ->and($summary['balances']['fund']['amount'])->toBe(2500.0)
        ->and($summary['balances']['cash']['url'])->toBeString()->not->toBeEmpty()
        ->and($summary['cycle']['label'])->toBeString()
        ->and($summary['cycle']['period_label'])->toBeString()
        ->and($summary['arrears']['visible'])->toBeBool()
        ->and($summary['member']['status'])->toBe('active')
        ->and($summary['member']['status_label'])->toBeString()
        ->and($summary['contributions']['posted_count'])->toBe(0)
        ->and($summary['contributions']['posted_total'])->toBe(0.0)
        ->and($summary['monthly'])->toBe(1000.0)
        ->and($summary['links']['ledger'])->toBeString()->not->toBeEmpty();
});

test('member workspace summary includes lifetime posted contribution totals', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    Contribution::query()->create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate(1, (int) now()->year),
        'amount' => 1000,
        'status' => 'posted',
        'posted_at' => now()->subMonths(2),
    ]);

    Contribution::query()->create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate(2, (int) now()->year),
        'amount' => 1000,
        'status' => 'posted',
        'posted_at' => now()->subMonth(),
    ]);

    Contribution::query()->create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate(3, (int) now()->year),
        'amount' => 1000,
        'status' => 'pending',
        'posted_at' => null,
    ]);

    MemberWorkspaceSummaryService::forgetCached((int) $member->id);

    $summary = app(MemberWorkspaceSummaryService::class)->summary($member->fresh());

    expect($summary['contributions']['posted_count'])->toBe(2)
        ->and($summary['contributions']['posted_total'])->toBe(2000.0)
        ->and($summary['contributions']['hint'])->toContain('2');
});

test('member workspace summary shows active loan chip when loan exists', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 1000,
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    Loan::factory()->for($member)->create([
        'status' => 'active',
        'amount_disbursed' => 15_000,
        'disbursed_at' => now()->subMonth(),
    ]);

    $summary = app(MemberWorkspaceSummaryService::class)->summary($member->fresh());

    expect($summary['loan'])->not->toBeNull()
        ->and($summary['loan']['id'])->toBeGreaterThan(0)
        ->and($summary['loan'])->toHaveKeys(['outstanding', 'outstanding_formatted', 'repay_percent'])
        ->and($summary['loan']['url'])->toBeString()->not->toBeEmpty();
});

test('member workspace summary caches composed snapshot per request', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    $member = $member->fresh(['cashAccount', 'fundAccount', 'parent', 'user']);
    $service = app(MemberWorkspaceSummaryService::class);

    $service->summary($member);

    $queryCount = 0;

    DB::listen(function () use (&$queryCount): void {
        $queryCount++;
    });

    $service->summary($member);

    expect($queryCount)->toBe(0);
});

test('member workspace summary flags prior period contribution arrears cheaply', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    $cycles = app(ContributionCycleService::class);
    [$curMonth, $curYear] = $cycles->currentOpenPeriod();
    $previous = Carbon::create($curYear, $curMonth, 1)->subMonthNoOverflow();
    $month = (int) $previous->month;
    $year = (int) $previous->year;

    Contribution::query()->create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate($month, $year),
        'amount' => 1000,
        'status' => 'posted',
        'posted_at' => $previous,
    ]);

    MemberWorkspaceSummaryService::forgetCached((int) $member->id);

    expect(app(MemberWorkspaceSummaryService::class)->arrearsVisible($member->fresh()))->toBeFalse();

    Contribution::query()
        ->where('member_id', $member->id)
        ->forPeriod($month, $year)
        ->update(['status' => 'pending', 'posted_at' => null]);

    MemberWorkspaceSummaryService::forgetCached((int) $member->id);

    expect(app(MemberWorkspaceSummaryService::class)->arrearsVisible($member->fresh()))->toBeTrue();
});

test('member workspace summary cache can be busted', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 500,
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    $service = app(MemberWorkspaceSummaryService::class);
    $first = $service->summary($member);

    $member->cashAccount?->update(['balance' => 999]);
    $second = $service->summary($member->fresh(['cashAccount']));

    expect($second['balances']['cash']['amount'])->toBe($first['balances']['cash']['amount']);

    MemberWorkspaceSummaryService::forgetCached((int) $member->id);

    $third = $service->summary($member->fresh(['cashAccount']));

    expect($third['balances']['cash']['amount'])->toBe(999.0);
});

test('member workspace summary reloads fund balance after account change without stale relations', function () {
    $member = Member::factory()->create([
        'name' => 'Stale Fund Member',
        'status' => 'active',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subMonths(3),
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    $member->load(['cashAccount', 'fundAccount']);
    $member->fundAccount?->update(['balance' => 1800]);
    $staleFund = clone $member->fundAccount->fresh();
    // Simulate an in-memory relation that was loaded before a later write.
    $staleFund->setRawAttributes(array_merge($staleFund->getAttributes(), ['balance' => '1800.00']));
    $member->setRelation('fundAccount', $staleFund);

    $service = app(MemberWorkspaceSummaryService::class);
    MemberWorkspaceSummaryService::forgetCached((int) $member->id);

    expect($service->summary($member)['balances']['fund']['amount'])->toBe(1800.0);

    $member->fundAccount()->update(['balance' => 0]);
    // Relation still carries the stale amount:
    expect((float) $member->fundAccount->balance)->toBe(1800.0);

    MemberWorkspaceSummaryService::forgetCached((int) $member->id);

    expect($service->summary($member)['balances']['fund']['amount'])->toBe(0.0);
});
