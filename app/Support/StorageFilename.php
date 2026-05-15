<?php

namespace App\Support;

use Illuminate\Support\Str;

class StorageFilename
{
    /**
     * @param  array<int, string|null>  $parts
     */
    public static function make(string $type, string $originalName, array $parts = []): string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION) ?: 'bin');
        $context = collect($parts)
            ->filter(fn ($p) => filled($p))
            ->map(fn ($p) => Str::slug((string) $p))
            ->filter()
            ->implode('-');

        $context = $context !== '' ? $context : 'file';
        $timestamp = now()->format('Ymd-His');
        $random = Str::lower(Str::random(6));

        return Str::slug($type).'-'.$context.'-'.$timestamp.'-'.$random.'.'.$extension;
    }
}
