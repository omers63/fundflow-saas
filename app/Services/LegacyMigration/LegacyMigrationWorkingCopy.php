<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Durable snapshots of uploaded migration CSVs so queued jobs and later steps
 * do not read deleted Filament uploads or stale cached paths.
 */
final class LegacyMigrationWorkingCopy
{
    public const MEMBERS_RELATIVE = 'legacy-migration/working/members.csv';

    public const LOANS_RELATIVE = 'legacy-migration/working/loans.csv';

    public const PAYMENTS_RELATIVE = 'legacy-migration/working/payments.csv';

    public function relativePathFor(string $kind): string
    {
        return match ($kind) {
            'members' => self::MEMBERS_RELATIVE,
            'loans' => self::LOANS_RELATIVE,
            'payments' => self::PAYMENTS_RELATIVE,
            default => throw new \InvalidArgumentException("Unknown migration CSV kind [{$kind}]."),
        };
    }

    public function storeContents(string $kind, string $contents): string
    {
        $relative = $this->relativePathFor($kind);
        $disk = Storage::disk('local');
        $directory = dirname($relative);

        if ($directory !== '.' && $directory !== '' && ! $disk->exists($directory)) {
            $disk->makeDirectory($directory);
        }

        if (! $disk->put($relative, $contents)) {
            throw new RuntimeException(__('Could not save the CSV file. Check storage permissions for :path.', [
                'path' => $disk->path($directory),
            ]));
        }

        $absolute = $disk->path($relative);

        if (! is_readable($absolute)) {
            throw new RuntimeException(__('The CSV file was saved but is not readable.'));
        }

        return $absolute;
    }

    /**
     * @param  array{members?: ?string, loans?: ?string, payments?: ?string}  $paths
     * @return array{members_path?: string, loans_path?: string, payments_path?: string}
     */
    public function snapshot(array $paths): array
    {
        $result = [];

        foreach (['members', 'loans', 'payments'] as $key) {
            $source = $paths[$key] ?? null;

            if (! is_string($source) || $source === '' || ! is_readable($source)) {
                continue;
            }

            $contents = file_get_contents($source);

            if ($contents === false) {
                continue;
            }

            $result["{$key}_path"] = $this->storeContents($key, $contents);
        }

        return $result;
    }

    /**
     * @return array{members_path?: string, loans_path?: string, payments_path?: string}
     */
    public function existingPaths(): array
    {
        $disk = Storage::disk('local');
        $result = [];

        foreach ([
            'members' => self::MEMBERS_RELATIVE,
            'loans' => self::LOANS_RELATIVE,
            'payments' => self::PAYMENTS_RELATIVE,
        ] as $key => $relative) {
            if (! $disk->exists($relative)) {
                continue;
            }

            $absolute = $disk->path($relative);

            if (is_readable($absolute)) {
                $result["{$key}_path"] = $absolute;
            }
        }

        return $result;
    }
}
