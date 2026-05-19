<?php

use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\MemberPortalAccountDetailInsightsService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('member');

    Member::query()->delete();
    User::query()->delete();

    $this->user = User::create([
        'name' => 'Detail Member',
        'email' => 'detail@member.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->user->id,
        'member_number' => 'MEM-DET01',
        'name' => 'Detail Member',
        'email' => 'detail@member.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);
    $this->member->load(['cashAccount', 'fundAccount']);
});

test('member portal account detail insights snapshot for own cash account', function () {
    auth('tenant')->login($this->user);

    Transaction::factory()->create([
        'account_id' => $this->member->cashAccount->id,
        'type' => 'credit',
        'amount' => 250,
        'transacted_at' => Carbon::now()->subDay(),
    ]);

    $snapshot = app(MemberPortalAccountDetailInsightsService::class)->snapshot($this->member->cashAccount);

    expect($snapshot)->toHaveKeys(['hero', 'kpis', 'recent', 'context', 'balance_display'])
        ->and($snapshot['kpis'])->toHaveCount(6)
        ->and($snapshot['account']['type'])->toBe('cash')
        ->and($snapshot['context']['panels'])->not->toBeEmpty();
});

test('member portal account detail insights rejects other members account', function () {
    $otherUser = User::create([
        'name' => 'Other',
        'email' => 'other@member.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $other = Member::create([
        'user_id' => $otherUser->id,
        'member_number' => 'MEM-OTH01',
        'name' => 'Other',
        'email' => 'other@member.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($other);
    $other->load('cashAccount');

    auth('tenant')->login($this->user);

    expect(app(MemberPortalAccountDetailInsightsService::class)->snapshot($other->cashAccount))->toBe([]);
});
