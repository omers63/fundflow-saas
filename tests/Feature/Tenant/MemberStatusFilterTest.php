<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Members\Pages\ListMembers;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\Loans\LoanDelinquencyService;
use App\Support\CollectionInsightsCache;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    CollectionInsightsCache::bumpAll();

    $this->actingAs(User::create([
        'name' => 'Status Filter Admin',
        'email' => 'status-filter-'.uniqid().'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]), 'tenant');
});

test('admin status filter options include badge variants', function () {
    expect(Member::adminStatusFilterOptions())->toHaveKeys([
        'active',
        'active_arrears',
        'inactive',
        'inactive_frozen',
        'withdrawn',
        'withdrawn_payout_hold',
    ])->and(Member::adminStatusFilterOptions()['active_arrears'])
        ->toBe(__('Active').' · '.__('arrears'));
});

test('admin status filter query matches badge variants', function () {
    $cleanActive = Member::factory()->create(['status' => 'active', 'name' => 'Clean Active']);
    $arrearsActive = Member::factory()->create(['status' => 'active', 'name' => 'Arrears Active']);
    $inactive = Member::factory()->create(['status' => 'inactive', 'frozen_at' => null, 'name' => 'Inactive Hold']);
    $frozen = Member::factory()->create(['status' => 'inactive', 'frozen_at' => now(), 'name' => 'Frozen Member']);
    $withdrawn = Member::factory()->create(['status' => 'withdrawn', 'payout_frozen_at' => null, 'name' => 'Withdrawn Member']);
    $payoutHold = Member::factory()->create(['status' => 'withdrawn', 'payout_frozen_at' => now(), 'name' => 'Payout Hold']);

    $delinquency = Mockery::mock(LoanDelinquencyService::class);
    $delinquency->shouldReceive('delinquentMemberIds')->andReturn([$arrearsActive->id]);
    app()->instance(LoanDelinquencyService::class, $delinquency);

    expect(Member::query()->adminStatusFilter('active')->pluck('id')->all())
        ->toContain($cleanActive->id)
        ->not->toContain($arrearsActive->id)
        ->and(Member::query()->adminStatusFilter('active_arrears')->pluck('id')->all())
        ->toContain($arrearsActive->id)
        ->not->toContain($cleanActive->id)
        ->and(Member::query()->adminStatusFilter('inactive')->pluck('id')->all())
        ->toContain($inactive->id)
        ->not->toContain($frozen->id)
        ->and(Member::query()->adminStatusFilter('inactive_frozen')->pluck('id')->all())
        ->toContain($frozen->id)
        ->not->toContain($inactive->id)
        ->and(Member::query()->adminStatusFilter('withdrawn')->pluck('id')->all())
        ->toContain($withdrawn->id)
        ->not->toContain($payoutHold->id)
        ->and(Member::query()->adminStatusFilter('withdrawn_payout_hold')->pluck('id')->all())
        ->toContain($payoutHold->id)
        ->not->toContain($withdrawn->id);
});

test('members list status filter can select active arrears', function () {
    $cleanActive = Member::factory()->create(['status' => 'active', 'name' => 'Clean Active Row']);
    $arrearsActive = Member::factory()->create(['status' => 'active', 'name' => 'Arrears Active Row']);

    $delinquency = Mockery::mock(LoanDelinquencyService::class)->shouldIgnoreMissing();
    $delinquency->shouldReceive('delinquentMemberIds')->andReturn([$arrearsActive->id]);
    $delinquency->shouldReceive('membersWithOutstandingArrearsIds')->andReturn([$arrearsActive->id]);
    $delinquency->shouldReceive('isDelinquent')->andReturnUsing(
        fn (Member $member): bool => $member->id === $arrearsActive->id,
    );
    app()->instance(LoanDelinquencyService::class, $delinquency);

    Livewire::test(ListMembers::class)
        ->filterTable('status', 'active_arrears')
        ->assertCanSeeTableRecords([$arrearsActive])
        ->assertCanNotSeeTableRecords([$cleanActive]);
});
