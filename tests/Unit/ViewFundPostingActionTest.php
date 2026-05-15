<?php

use App\Filament\Support\ViewActions\ViewFundPostingAction;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Member;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    FundPosting::query()->delete();
    Member::query()->delete();
});

it('formats fund posting data for the view modal', function () {
    $member = Member::factory()->create(['name' => 'Jane Member']);

    $posting = FundPosting::create([
        'member_id' => $member->id,
        'posting_date' => '2026-05-10',
        'amount' => 1500.50,
        'reference' => 'REF-99',
        'comments' => 'Monthly contribution',
        'status' => 'pending',
    ]);

    $data = ViewFundPostingAction::formatRecordData($posting);

    expect($data['member_name'])->toBe('Jane Member')
        ->and($data['posting_date_display'])->toBe('May 10, 2026')
        ->and($data['amount_display'])->toContain('1,500.50')
        ->and($data['status_display'])->toBe('Pending')
        ->and($data['reference_display'])->toBe('REF-99')
        ->and($data['comments_display'])->toBe('Monthly contribution');
});
