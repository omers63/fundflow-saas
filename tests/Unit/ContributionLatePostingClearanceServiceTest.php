<?php

declare(strict_types=1);

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Services\ContributionLatePostingClearanceService;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Contribution::query()->delete();
    Member::query()->delete();

    $this->member = Member::create([
        'member_number' => 'MEM-LPC',
        'name' => 'Late Clear Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $this->service = app(ContributionLatePostingClearanceService::class);
});

test('clearing late posting removes is_late without changing status', function () {
    $contribution = Contribution::create([
        'member_id' => $this->member->id,
        'period' => Contribution::periodDate(3, 2025),
        'amount' => 1000,
        'amount_due' => 1000,
        'amount_collected' => 1000,
        'status' => 'posted',
        'is_late' => true,
        'posted_at' => Carbon::parse('2025-04-01'),
        'notes' => 'Original note',
    ]);

    expect($this->service->clearContribution($contribution, 'Board approved'))
        ->toBe('cleared');

    $contribution->refresh();

    expect($contribution->is_late)->toBeFalse()
        ->and($contribution->status)->toBe('posted')
        ->and($contribution->notes)->toContain('Board approved')
        ->and($contribution->notes)->toContain('Original note');
});

test('pending contribution cannot be cleared as late posting', function () {
    $contribution = Contribution::create([
        'member_id' => $this->member->id,
        'period' => Contribution::periodDate(4, 2025),
        'amount' => 1000,
        'status' => 'pending',
        'is_late' => true,
    ]);

    expect(fn () => $this->service->clearContribution($contribution))
        ->toThrow(InvalidArgumentException::class);
});

test('already on time contribution returns already_clear', function () {
    $contribution = Contribution::create([
        'member_id' => $this->member->id,
        'period' => Contribution::periodDate(5, 2025),
        'amount' => 1000,
        'status' => 'posted',
        'is_late' => false,
        'posted_at' => now(),
    ]);

    expect($this->service->clearContribution($contribution))->toBe('already_clear');
});
