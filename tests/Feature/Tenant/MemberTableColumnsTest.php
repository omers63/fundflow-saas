<?php

use App\Filament\Support\MemberTableColumns;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use Filament\Facades\Filament;
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
