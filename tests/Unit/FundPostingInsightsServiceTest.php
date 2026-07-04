<?php

use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Member;
use App\Services\FundPostingInsightsService;
use App\Support\BusinessDaySettings;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    FundPosting::query()->delete();
    Member::query()->delete();
});

test('insights snapshot aggregates deposit pipeline metrics', function () {
    BusinessDaySettings::saveFromForm('2026-06-15');

    $member = Member::factory()->create(['name' => 'Pending Member']);

    $recentPending = FundPosting::create([
        'member_id' => $member->id,
        'posting_date' => '2026-06-14',
        'amount' => 500,
        'status' => 'pending',
        'attachment' => 'receipts/test.pdf',
    ]);
    $recentPending->forceFill(['created_at' => Carbon::parse('2026-06-14 12:00:00')])->save();

    $pendingOverSla = FundPosting::create([
        'member_id' => $member->id,
        'posting_date' => now()->toDateString(),
        'amount' => 2500,
        'status' => 'pending',
    ]);
    $pendingOverSla->forceFill(['created_at' => Carbon::parse('2026-06-09 12:00:00')])->save();

    $accepted = FundPosting::create([
        'member_id' => $member->id,
        'posting_date' => '2026-06-13',
        'amount' => 1000,
        'status' => 'accepted',
        'reviewed_at' => Carbon::parse('2026-06-14 12:00:00'),
    ]);
    $accepted->forceFill(['created_at' => Carbon::parse('2026-06-13 12:00:00')])->save();

    $rejected = FundPosting::create([
        'member_id' => $member->id,
        'posting_date' => '2026-06-14',
        'amount' => 750,
        'status' => 'rejected',
        'reviewed_at' => Carbon::parse('2026-06-15 12:00:00'),
    ]);
    $rejected->forceFill(['created_at' => Carbon::parse('2026-06-14 12:00:00')])->save();

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
        ->and($snapshot['oldest_pending'])->not->toBeEmpty()
        ->and($snapshot['treasury_forecast'])->toHaveKeys([
            'pending_deposit_amount',
            'pending_cash_out_amount',
            'projected_available_cash',
        ]);

    BusinessDaySettings::saveFromForm(null);
});
