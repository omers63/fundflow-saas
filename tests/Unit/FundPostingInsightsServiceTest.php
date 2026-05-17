<?php

use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Member;
use App\Services\FundPostingInsightsService;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    FundPosting::query()->delete();
    Member::query()->delete();
});

test('insights snapshot aggregates deposit pipeline metrics', function () {
    $member = Member::factory()->create(['name' => 'Pending Member']);

    FundPosting::create([
        'member_id' => $member->id,
        'posting_date' => now()->toDateString(),
        'amount' => 500,
        'status' => 'pending',
        'attachment' => 'receipts/test.pdf',
    ]);

    $pendingOverSla = FundPosting::create([
        'member_id' => $member->id,
        'posting_date' => now()->toDateString(),
        'amount' => 2500,
        'status' => 'pending',
    ]);
    $pendingOverSla->forceFill(['created_at' => now()->subDays(5)])->save();

    $accepted = FundPosting::create([
        'member_id' => $member->id,
        'posting_date' => now()->toDateString(),
        'amount' => 1000,
        'status' => 'accepted',
        'reviewed_at' => now()->subDay(),
    ]);
    $accepted->forceFill(['created_at' => now()->subDays(2)])->save();

    $rejected = FundPosting::create([
        'member_id' => $member->id,
        'posting_date' => now()->toDateString(),
        'amount' => 750,
        'status' => 'rejected',
        'reviewed_at' => now(),
    ]);
    $rejected->forceFill(['created_at' => now()->subDays(1)])->save();

    $snapshot = app(FundPostingInsightsService::class)->snapshot();

    expect($snapshot['pending'])->toBe(2)
        ->and($snapshot['accepted'])->toBe(1)
        ->and($snapshot['rejected'])->toBe(1)
        ->and($snapshot['total'])->toBe(4)
        ->and($snapshot['pending_over_sla'])->toBe(1)
        ->and($snapshot['acceptance_rate'])->toBe(50.0)
        ->and($snapshot['pending_amount_total'])->toBe(3000.0)
        ->and($snapshot['docs']['pending_with_receipt'])->toBe(1)
        ->and($snapshot['docs']['receipt_rate'])->toBe(50)
        ->and($snapshot['trend'])->toHaveCount(6)
        ->and($snapshot['sparkline'])->toHaveCount(8)
        ->and($snapshot['amount_breakdown'])->toHaveCount(3)
        ->and($snapshot['oldest_pending'])->not->toBeEmpty();
});
