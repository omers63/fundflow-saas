<?php

use App\Filament\Support\MemberTableColumns;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
});

test('member table columns resolve member edit urls', function () {
    $member = Member::factory()->create();

    $editUrl = MemberTableColumns::memberRecordEditUrl($member);

    expect($editUrl)
        ->toBe(MemberResource::getUrl('edit', ['record' => $member]))
        ->and($editUrl)->toContain((string) $member->getKey());
});

test('member table columns resolve related member edit urls', function () {
    $member = Member::factory()->create();
    $contribution = Contribution::factory()->for($member)->create();

    expect(MemberTableColumns::relatedMemberEditUrl($contribution))
        ->toBe(MemberResource::getUrl('edit', ['record' => $member]));
});

test('member table columns resolve member id edit urls', function () {
    $member = Member::factory()->create();

    expect(MemberTableColumns::memberIdEditUrl(['member_id' => $member->id]))
        ->toBe(MemberResource::getUrl('edit', ['record' => $member]));
});

test('member table columns resolve urls for related and array records', function () {
    $member = Member::factory()->create();
    $contribution = Contribution::factory()->for($member)->create();
    $loan = Loan::factory()->for($member)->create();

    expect(MemberTableColumns::resolveMemberUrl('member.name', $contribution))
        ->toBe(MemberResource::getUrl('edit', ['record' => $member]))
        ->and(MemberTableColumns::resolveMemberUrl('loan.member.name', $loan))
        ->toBe(MemberResource::getUrl('edit', ['record' => $member]))
        ->and(MemberTableColumns::resolveMemberUrl('member_name', ['member_id' => $member->id]))
        ->toBe(MemberResource::getUrl('edit', ['record' => $member]))
        ->and(MemberTableColumns::resolveMemberUrl('name', $member))
        ->toBe(MemberResource::getUrl('edit', ['record' => $member]));
});

test('tenant panel auto-links member name table columns', function () {
    $member = Member::factory()->create();
    $contribution = Contribution::factory()->for($member)->create();

    $column = TextColumn::make('member.name')->record($contribution);

    expect($column->getUrl($member->name))
        ->toBe(MemberResource::getUrl('edit', ['record' => $member]));
});
