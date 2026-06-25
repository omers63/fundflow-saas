<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Filament file upload fields may expose state as a disk-relative string, a UUID-keyed map of paths,
 * or a Livewire temporary upload object before/without persistence.
 */
final class FilamentStoredUploadPath
{
    public static function toRelativePath(mixed $state): ?string
    {
        if (is_string($state)) {
            return trim($state) === '' ? null : $state;
        }

        if (is_array($state)) {
            $paths = array_filter(
                Arr::wrap($state),
                static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
            );

            $first = Arr::first($paths);

            return is_string($first) ? $first : null;
        }

        return null;
    }

    /**
     * @return array{absolutePath: string, relativePathForDeletion: ?string}|null
     */
    public static function tryResolveReadableCsvToAbsolutePath(mixed $state): ?array
    {
        if ($state instanceof TemporaryUploadedFile) {
            return self::resolveTemporaryUploadedFile($state);
        }

        if (is_array($state)) {
            foreach (array_reverse(Arr::wrap($state)) as $value) {
                if ($value instanceof TemporaryUploadedFile) {
                    $candidate = self::resolveTemporaryUploadedFile($value);

                    if ($candidate !== null) {
                        return $candidate;
                    }

                    continue;
                }

                if (is_string($value) && trim($value) !== '') {
                    $candidate = self::resolveStoredRelativePath(trim($value));

                    if ($candidate !== null) {
                        return $candidate;
                    }

                    continue;
                }

                if (is_array($value)) {
                    $candidate = self::tryResolveReadableCsvToAbsolutePath($value);

                    if ($candidate !== null) {
                        return $candidate;
                    }
                }
            }

            return null;
        }

        $relative = self::toRelativePath($state);

        if ($relative !== null) {
            return self::resolveStoredRelativePath($relative);
        }

        if (is_string($state) && str_starts_with($state, '/') && is_readable($state)) {
            return [
                'absolutePath' => $state,
                'relativePathForDeletion' => null,
            ];
        }

        return null;
    }

    /**
     * @return array{absolutePath: string, relativePathForDeletion: ?string}|null
     */
    private static function resolveTemporaryUploadedFile(TemporaryUploadedFile $file): ?array
    {
        $absolutePath = $file->getRealPath();

        if ($absolutePath !== '' && is_readable($absolutePath)) {
            return [
                'absolutePath' => $absolutePath,
                'relativePathForDeletion' => null,
            ];
        }

        return null;
    }

    /**
     * @return array{absolutePath: string, relativePathForDeletion: ?string}|null
     */
    private static function resolveStoredRelativePath(string $relative): ?array
    {
        $absolutePath = Storage::disk('local')->path($relative);

        if (is_readable($absolutePath)) {
            return [
                'absolutePath' => $absolutePath,
                'relativePathForDeletion' => $relative,
            ];
        }

        return null;
    }
}
