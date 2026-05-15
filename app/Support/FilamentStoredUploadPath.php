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
                static fn (mixed $v): bool => is_string($v) && trim($v) !== ''
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
            $absolutePath = $state->getRealPath();
            if ($absolutePath !== '' && is_readable($absolutePath)) {
                return [
                    'absolutePath' => $absolutePath,
                    'relativePathForDeletion' => null,
                ];
            }

            return null;
        }

        $relative = self::toRelativePath($state);
        if ($relative !== null) {
            $absolutePath = Storage::disk('local')->path($relative);
            if (is_readable($absolutePath)) {
                return [
                    'absolutePath' => $absolutePath,
                    'relativePathForDeletion' => $relative,
                ];
            }
        }

        if (is_string($state) && str_starts_with($state, '/') && is_readable($state)) {
            return [
                'absolutePath' => $state,
                'relativePathForDeletion' => null,
            ];
        }

        return null;
    }
}
