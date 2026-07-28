<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Short-lived, generation-bumped cache for contribution / loan collection insight snapshots.
 *
 * Browsing cycles reuses cached payloads; posting/apply bumps the generation so the next
 * read recomputes. Keys are tenant-scoped via the active cache store prefix.
 */
final class CollectionInsightsCache
{
    public const DOMAIN_CONTRIBUTIONS = 'contribution_insights';

    public const DOMAIN_LOAN_EMI = 'loan_emi_insights';

    public const DOMAIN_MEMBERS = 'member_insights';

    public const DOMAIN_DELINQUENCY = 'delinquency_insights';

    private const TTL_SECONDS = 300;

    private const GENERATION_TTL_SECONDS = 3600;

    public static function remember(string $domain, string $suffix, Closure $callback): mixed
    {
        $generation = self::generation($domain);

        // Use Cache::remember directly (not TenantRuntimeCache::remember): the latter wraps
        // once() at a single call site, so different keys would collide within one request.
        return Cache::remember(
            "{$domain}:{$generation}:{$suffix}",
            self::TTL_SECONDS,
            $callback,
        );
    }

    public static function bump(string $domain): void
    {
        $key = self::generationKey($domain);
        $next = (int) Cache::get($key, 1) + 1;
        Cache::put($key, $next, self::GENERATION_TTL_SECONDS);
    }

    public static function bumpAll(): void
    {
        self::bump(self::DOMAIN_CONTRIBUTIONS);
        self::bump(self::DOMAIN_LOAN_EMI);
        self::bump(self::DOMAIN_MEMBERS);
        self::bump(self::DOMAIN_DELINQUENCY);
    }

    private static function generation(string $domain): int
    {
        return (int) Cache::get(self::generationKey($domain), 1);
    }

    private static function generationKey(string $domain): string
    {
        return "{$domain}:generation";
    }
}
