<?php

use App\Models\Central\Role;
use App\Models\Central\User;

test('can create a central user', function () {
    // Create the role first as the User model assigns it on creation
    Role::create(['name' => 'user']);

    $user = User::factory()->create();

    expect($user)
        ->toBeInstanceOf(User::class)
        ->name->not->toBeNull()
        ->email->not->toBeNull();

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'email' => $user->email,
    ], 'mysql');
});
