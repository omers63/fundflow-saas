<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Exceptions;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;

test('unknown hostnames and raw ip hits return not found instead of a tenant error', function (string $url) {
    Exceptions::fake();

    $this->get($url)->assertNotFound();

    Exceptions::assertNotReported(TenantCouldNotBeIdentifiedOnDomainException::class);
})->with([
    'unknown hostname' => 'http://unknown-fund.example/',
    'documentation ipv4' => 'http://192.0.2.1/',
    'documentation ipv6' => 'http://[2001:db8::1]/',
]);
