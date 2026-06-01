<?php

declare(strict_types=1);

namespace App\Support;

use Filament\Resources\Resource;

final class FilamentTableListUrl
{
    /**
     * Build one Filament table filter entry for the `filters` URL query key.
     *
     * @return array<string, array<string, string>>
     */
    public static function filter(string $name, mixed $value): array
    {
        return [
            $name => [
                'value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
            ],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  ...$filterSets
     * @return array<string, array<string, mixed>>
     */
    public static function mergeFilters(array ...$filterSets): array
    {
        return array_merge(...$filterSets);
    }

    /**
     * @param  class-string<resource>  $resource
     * @param  array<string, mixed>  $parameters
     */
    public static function index(string $resource, array $parameters = []): string
    {
        return $resource::getUrl('index', $parameters);
    }

    /**
     * @param  class-string<resource>  $resource
     * @param  array<string, array<string, mixed>>  $filters
     */
    public static function indexWithFilters(string $resource, array $filters, array $parameters = []): string
    {
        if ($filters !== []) {
            $parameters['filters'] = $filters;
        }

        return self::index($resource, $parameters);
    }
}
