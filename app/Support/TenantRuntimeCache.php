<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Per-request memoization layered on a short-lived tenant-scoped cache store.
 *
 * Keys are locale-scoped: dashboard / insight payloads embed translated UI strings, so
 * a shared tenant key would stick Arabic labels under English (and vice versa) for the TTL.
 */
final class TenantRuntimeCache
{
    /** @var array<string, mixed> */
    private static array $requestMemo = [];

    public static function remember(string $key, int $seconds, Closure $callback): mixed
    {
        $localizedKey = self::localizedKey($key);

        if (array_key_exists($localizedKey, self::$requestMemo)) {
            return self::$requestMemo[$localizedKey];
        }

        return self::$requestMemo[$localizedKey] = Cache::remember($localizedKey, $seconds, $callback);
    }

    public static function forget(string $key): void
    {
        foreach (AppLocale::SUPPORTED as $locale) {
            $localizedKey = self::localizedKey($key, $locale);
            unset(self::$requestMemo[$localizedKey]);
            Cache::forget($localizedKey);
        }

        // Legacy unlocalized entries (pre locale-scoped keys).
        unset(self::$requestMemo[$key]);
        Cache::forget($key);
    }

    public static function flushRequestMemo(): void
    {
        self::$requestMemo = [];
    }

    public static function localizedKey(string $key, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        if (! is_string($locale) || $locale === '' || ! AppLocale::isSupported($locale)) {
            $locale = AppLocale::DEFAULT;
        }

        return $key.':locale:'.$locale;
    }
}
