<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Per-request memoization layered on a short-lived tenant-scoped cache store.
 */
final class TenantRuntimeCache
{
    public static function remember(string $key, int $seconds, Closure $callback): mixed
    {
        return once(fn (): mixed => Cache::remember($key, $seconds, $callback));
    }

    public static function forget(string $key): void
    {
        Cache::forget($key);
    }
}
