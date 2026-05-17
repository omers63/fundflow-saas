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

test('member table columns resolve member view urls', function () {
    $member = Member::factory()->create();

    $viewUrl = MemberTableColumns::memberRecordViewUrl($member);

    expect($viewUrl)
        ->toBe(MemberResource::getUrl('view', ['record' => $member]))
        ->and($viewUrl)->toContain((string) $member->getKey());
});

test('member table columns resolve related member view urls', function () {
    $member = Member::factory()->create();
    $contribution = Contribution::factory()->for($member)->create();

    expect(MemberTableColumns::relatedMemberViewUrl($contribution))
        ->toBe(MemberResource::getUrl('view', ['record' => $member]));
});

test('member table columns resolve member id view urls', function () {
    $member = Member::factory()->create();

    expect(MemberTableColumns::memberIdViewUrl(['member_id' => $member->id]))
        ->toBe(MemberResource::getUrl('view', ['record' => $member]));
});
