<?php

declare(strict_types=1);

use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Services\Loans\LoanQueuePriorityScoreService;
use App\Support\BusinessDaySettings;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

test('emergency loan scores higher than standard loan with same tenure and wait time', function () {
    $member = Member::factory()->create([
        'joined_at' => now()->subYears(3),
        'status' => 'active',
    ]);

    $standard = Loan::factory()->create([
        'member_id' => $member->id,
        'is_emergency' => false,
        'applied_at' => now()->subDays(2),
        'status' => 'pending',
    ]);

    $emergency = Loan::factory()->create([
        'member_id' => $member->id,
        'is_emergency' => true,
        'applied_at' => now()->subDays(2),
        'status' => 'pending',
    ]);

    $service = app(LoanQueuePriorityScoreService::class);

    expect($service->calculate($emergency))->toBeGreaterThan($service->calculate($standard));
});

test('delinquent member loses standing bonus points', function () {
    $cleanMember = Member::factory()->create([
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $inactiveMember = Member::factory()->create([
        'joined_at' => now()->subYear(),
        'status' => 'inactive',
    ]);

    $cleanLoan = Loan::factory()->create([
        'member_id' => $cleanMember->id,
        'is_emergency' => false,
        'applied_at' => now()->subDay(),
        'status' => 'pending',
    ]);

    $inactiveLoan = Loan::factory()->create([
        'member_id' => $inactiveMember->id,
        'is_emergency' => false,
        'applied_at' => now()->subDay(),
        'status' => 'pending',
    ]);

    $service = app(LoanQueuePriorityScoreService::class);

    expect($service->calculate($cleanLoan))->toBe($service->calculate($inactiveLoan) + 10);
});

test('priority sort orders higher scores first for pending loans', function () {
    $member = Member::factory()->create([
        'joined_at' => now()->subYears(5),
        'status' => 'active',
    ]);

    $lower = Loan::factory()->create([
        'member_id' => $member->id,
        'is_emergency' => false,
        'applied_at' => now(),
        'status' => 'pending',
    ]);

    $higher = Loan::factory()->create([
        'member_id' => $member->id,
        'is_emergency' => true,
        'applied_at' => now()->subDays(10),
        'status' => 'pending',
    ]);

    $ordered = app(LoanQueuePriorityScoreService::class)
        ->applySort(Loan::query()->where('loans.status', 'pending')->select('loans.*'))
        ->pluck('loans.id')
        ->all();

    expect($ordered[0])->toBe($higher->id);
});

test('queue wait bonus uses configured business day not calendar date', function () {
    BusinessDaySettings::saveFromForm('2026-06-15');

    $member = Member::factory()->create([
        'joined_at' => Carbon::parse('2020-01-01'),
        'status' => 'active',
    ]);

    $loan = Loan::factory()->create([
        'member_id' => $member->id,
        'is_emergency' => false,
        'applied_at' => Carbon::parse('2026-06-01'),
        'status' => 'pending',
    ]);

    $scoreOnBusinessDay = app(LoanQueuePriorityScoreService::class)->calculate($loan->fresh());

    BusinessDaySettings::saveFromForm('2026-06-01');

    $scoreEarlierBusinessDay = app(LoanQueuePriorityScoreService::class)->calculate($loan->fresh());

    expect($scoreOnBusinessDay)->toBeGreaterThan($scoreEarlierBusinessDay);

    BusinessDaySettings::saveFromForm(null);
});
