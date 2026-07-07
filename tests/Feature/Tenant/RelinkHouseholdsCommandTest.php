<?php

declare(strict_types=1);

use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Member::query()->delete();
    User::query()->where('is_admin', false)->delete();
});

function makeRelinkMember(string $memberNumber, string $name, string $email): Member
{
    $user = User::create([
        'name' => $name,
        'email' => $email,
        'password' => bcrypt('password123'),
        'is_admin' => false,
    ]);

    return Member::create([
        'user_id' => $user->id,
        'member_number' => $memberNumber,
        'name' => $name,
        'email' => $email,
        'household_email' => $email,
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ]);
}

function writeRelinkCsv(string $contents): string
{
    $path = storage_path('app/relink-households-test.csv');
    file_put_contents($path, $contents);

    return $path;
}

test('relink command links dependents from parent_member_number column', function () {
    $parent = makeRelinkMember('RL-P', 'Relink Parent', 'relink-household@example.test');
    $dep1 = makeRelinkMember('RL-D1', 'Relink Child One', 'child-one@example.test');
    $dep2 = makeRelinkMember('RL-D2', 'Relink Child Two', 'child-two@example.test');

    $path = writeRelinkCsv(implode("\n", [
        'member_number,parent_member_number',
        'RL-P,',
        'RL-D1,RL-P',
        'RL-D2,RL-P',
    ]));

    $this->artisan('households:relink', ['--path' => $path, '--tenants' => ['testing']])
        ->assertSuccessful();

    $this->initializeTenancy();

    expect($dep1->fresh()->parent_member_id)->toBe($parent->id)
        ->and($dep2->fresh()->parent_member_id)->toBe($parent->id)
        ->and($dep1->fresh()->email)->toBe('relink-household@example.test')
        ->and($dep1->fresh()->household_email)->toBe('relink-household@example.test')
        ->and($parent->fresh()->parent_member_id)->toBeNull()
        ->and($parent->fresh()->dependents()->count())->toBe(2);

    @unlink($path);
});

test('relink command dry run reports links without writing', function () {
    $parent = makeRelinkMember('RL-P', 'Relink Parent', 'relink-household@example.test');
    $dep = makeRelinkMember('RL-D1', 'Relink Child', 'child@example.test');

    $path = writeRelinkCsv(implode("\n", [
        'member_number,parent_member_number',
        'RL-D1,RL-P',
    ]));

    $this->artisan('households:relink', ['--path' => $path, '--dry-run' => true, '--tenants' => ['testing']])
        ->assertSuccessful();

    $this->initializeTenancy();

    expect($dep->fresh()->parent_member_id)->toBeNull()
        ->and($parent->fresh()->dependents()->count())->toBe(0);

    @unlink($path);
});

test('relink command fails when parent_member_number column is missing', function () {
    $path = writeRelinkCsv(implode("\n", [
        'member_number,name',
        'RL-D1,Someone',
    ]));

    $this->artisan('households:relink', ['--path' => $path, '--tenants' => ['testing']])
        ->assertFailed();

    @unlink($path);
});
