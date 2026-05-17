<?php

declare(strict_types=1);

test('central admin login shows auth header and language switcher', function () {
    $domain = config('tenancy.central_domain');

    $response = $this->get('http://'.$domain.'/admin/login');

    $response
        ->assertSuccessful()
        ->assertSee('fund-auth-shell', false)
        ->assertSee('central-auth-header', false)
        ->assertSee('language-switch-trigger', false)
        ->assertSee('data-language-tooltip', false);
});

test('central locale switch route sets session and redirects back', function () {
    $domain = config('tenancy.central_domain');

    $this->get('http://'.$domain.'/locale/en')
        ->assertRedirect()
        ->assertSessionHas('locale', 'en');
});
