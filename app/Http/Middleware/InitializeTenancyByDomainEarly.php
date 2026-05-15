<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;
use Stancl\Tenancy\Tenancy;
use Symfony\Component\HttpFoundation\Response;

class InitializeTenancyByDomainEarly
{
    public function __construct(
        protected Tenancy $tenancy,
        protected DomainTenantResolver $resolver,
    ) {}

    /**
     * Initialize tenancy before the web middleware group so that database
     * sessions, CSRF tokens, and cache use the tenant connection.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->tenancy->initialized) {
            return $next($request);
        }

        $host = $request->getHost();
        $centralDomain = config('tenancy.central_domain');

        if ($host === $centralDomain) {
            return $next($request);
        }

        try {
            $tenant = $this->resolver->resolve($host);
            $this->tenancy->initialize($tenant);
        } catch (TenantCouldNotBeIdentifiedException) {
            // Silently skip — route-level middleware will handle the error
        }

        return $next($request);
    }
}
