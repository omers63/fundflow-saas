<?php

declare(strict_types=1);

use App\Filament\Support\TableGrouping;
use App\Models\Tenant\Member;
use Filament\Tables\Grouping\Group;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Member::query()->delete();
});

test('member parent group query supports monthly contribution sum without ambiguous columns', function () {
    $parent = Member::create([
        'member_number' => 'MEM-PARENT-01',
        'name' => 'Parent Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    Member::create([
        'member_number' => 'MEM-DEP-01',
        'name' => 'Dependent Member',
        'parent_member_id' => $parent->id,
        'monthly_contribution_amount' => 200,
        'joined_at' => now()->subMonths(6),
        'status' => 'active',
    ]);

    /** @var Group $parentGroup */
    $parentGroup = collect(TableGrouping::members())
        ->first(fn (Group $group): bool => $group->getColumn() === 'parent_member_id');

    $query = $parentGroup->groupQuery(Member::query()->toBase(), new Member);

    $totals = $query
        ->selectRaw('sum(members.monthly_contribution_amount) as contribution_total, members.parent_member_id')
        ->get();

    expect($totals)->not->toBeEmpty()
        ->and((float) $totals->firstWhere('parent_member_id', $parent->id)?->contribution_total)->toBe(200.0);
});
