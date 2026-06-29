<?php

declare(strict_types=1);

use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Services\Loans\LoanQueuePriorityScoreService;
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
