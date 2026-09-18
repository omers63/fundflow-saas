<?php

declare(strict_types=1);

use App\Models\Central\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Livewire\Livewire;

test('unverified central user can access admin panel when must-verify is unused', function () {
    $user = User::factory()->unverified()->create([
        'email' => 'central-unverified-'.uniqid().'@example.com',
    ]);

    expect($user->email_verified_at)->toBeNull()
        ->and($user->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
});

test('central admin can authenticate to the admin panel', function () {
    $domain = config('tenancy.central_domain');
    $user = User::factory()->create([
        'email' => 'central-login-'.uniqid().'@example.com',
        'password' => 'password',
    ]);

    $this->get('http://'.$domain.'/admin/login')->assertSuccessful();

    Livewire::test(Login::class)
        ->fillForm([
            'email' => $user->email,
            'password' => 'password',
        ])
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $this->assertAuthenticatedAs($user);
});
