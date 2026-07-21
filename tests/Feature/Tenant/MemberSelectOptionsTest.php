<?php

declare(strict_types=1);

use App\Filament\Support\BankTransactionTableActions;
use App\Filament\Support\MemberSelectOptions;
use App\Filament\Tenant\Support\TenantPortalActionModal;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
});

test('member select options use member number then name', function () {
    $member = Member::factory()->create([
        'member_number' => 'MEM-4242',
        'name' => 'Searchable Member',
        'status' => 'active',
    ]);

    expect(MemberSelectOptions::label($member))->toBe('MEM-4242 - Searchable Member')
        ->and(MemberSelectOptions::activeOptions())
        ->toHaveKey($member->id)
        ->and(MemberSelectOptions::activeOptions()[$member->id])->toBe('MEM-4242 - Searchable Member');
});

test('member select search matches member number and name', function () {
    $byNumber = Member::factory()->create([
        'member_number' => 'NUM-777',
        'name' => 'Alpha Person',
        'status' => 'active',
    ]);
    $byName = Member::factory()->create([
        'member_number' => 'NUM-888',
        'name' => 'Zed Finder',
        'status' => 'active',
    ]);

    expect(MemberSelectOptions::search('NUM-777'))->toHaveKey($byNumber->id)
        ->and(MemberSelectOptions::search('Finder'))->toHaveKey($byName->id)
        ->and(MemberSelectOptions::search('no-such-member'))->toBe([]);
});

test('post to member confirmation modal uses with-fields window class', function () {
    $admin = User::create([
        'name' => 'Member Select Admin',
        'email' => 'member-select-'.uniqid('', true).'@fund.test',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $this->actingAs($admin, 'tenant');

    $action = TenantPortalActionModal::applyConfirmation(
        BankTransactionTableActions::postToMember(),
    );

    $attributes = $action->getExtraModalWindowAttributes();

    expect($attributes['class'] ?? '')
        ->toContain('ff-tenant-confirm-modal-window--with-fields');
});
