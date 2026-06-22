<?php

declare(strict_types=1);

namespace App\Support;

final class TenantAbsoluteUrl
{
    public static function resolve(string $url): string
    {
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = self::tenantOrigin() . '/' . ltrim($url, '/');
        }

        return self::ensureTenantHost($url);
    }

    protected static function ensureTenantHost(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host'])) {
            return $url;
        }

        $centralDomains = config('tenancy.central_domains', []);

        if (!in_array($parts['host'], $centralDomains, true)) {
            return $url;
        }

        $tenantDomain = self::tenantDomain();

        if ($tenantDomain === null) {
            return $url;
        }

        $scheme = $parts['scheme'] ?? (str_starts_with((string) config('app.url'), 'https') ? 'https' : 'http');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return "{$scheme}://{$tenantDomain}{$port}{$path}{$query}{$fragment}";
    }

    protected static function tenantOrigin(): string
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';
        $tenantDomain = self::tenantDomain();

        if ($tenantDomain !== null) {
            return "{$scheme}://{$tenantDomain}";
        }

        return rtrim((string) config('app.url'), '/');
    }

    protected static function tenantDomain(): ?string
    {
        $tenant = tenant();

        if ($tenant !== null) {
            return $tenant->domains()->first()?->domain;
        }

        $host = request()->getHost();
        $centralDomains = config('tenancy.central_domains', []);

        return in_array($host, $centralDomains, true) ? null : $host;
    }
}
