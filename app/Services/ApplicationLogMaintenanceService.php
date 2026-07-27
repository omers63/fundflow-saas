<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Truncates allow-listed host/app log files the PHP process can write.
 * Prefer truncate over unlink so supervisor/cron keep the same inode.
 */
final class ApplicationLogMaintenanceService
{
    /**
     * @return array<string, array{label: string, path: string, group: string, description: string}>
     */
    public function definitions(): array
    {
        // Use base_path — storage_path() is tenant-suffixed while cron/supervisor
        // write the operational logs under the central storage/logs directory.
        $storageLogs = base_path('storage/logs');

        return [
            'application' => [
                'label' => __('Application'),
                'path' => $storageLogs.DIRECTORY_SEPARATOR.'laravel.log',
                'group' => 'app',
                'description' => __('Laravel application errors and exceptions.'),
            ],
            'scheduler' => [
                'label' => __('Scheduler'),
                'path' => $storageLogs.DIRECTORY_SEPARATOR.'scheduler.log',
                'group' => 'app',
                'description' => __('Cron schedule:run output.'),
            ],
            'queue' => [
                'label' => __('Queue worker'),
                'path' => $storageLogs.DIRECTORY_SEPARATOR.'queue-worker.log',
                'group' => 'app',
                'description' => __('Supervisor queue worker stdout/stderr.'),
            ],
            'reverb' => [
                'label' => __('Reverb'),
                'path' => $storageLogs.DIRECTORY_SEPARATOR.'reverb.log',
                'group' => 'app',
                'description' => __('WebSocket / Reverb process log.'),
            ],
            'nginx_access' => [
                'label' => __('Nginx access'),
                'path' => '/var/log/nginx/access.log',
                'group' => 'host',
                'description' => __('HTTP access log (current file only, not rotated archives).'),
            ],
            'nginx_error' => [
                'label' => __('Nginx error'),
                'path' => '/var/log/nginx/error.log',
                'group' => 'host',
                'description' => __('HTTP error log (current file only, not rotated archives).'),
            ],
            'php_fpm' => [
                'label' => __('PHP-FPM'),
                'path' => $this->firstExistingPath([
                    '/var/log/php8.4-fpm.log',
                    '/var/log/php8.3-fpm.log',
                    '/var/log/php-fpm.log',
                ]) ?? '/var/log/php-fpm.log',
                'group' => 'host',
                'description' => __('Owned by root — clear from the host as an operator.'),
            ],
            'syslog' => [
                'label' => __('Syslog / cron'),
                'path' => '/var/log/syslog',
                'group' => 'host',
                'description' => __('Cron and system messages — clear from the host as an operator.'),
            ],
        ];
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     path: string,
     *     group: string,
     *     description: string,
     *     exists: bool,
     *     readable: bool,
     *     writable: bool,
     *     size_bytes: int,
     *     size_label: string
     * }>
     */
    public function catalog(): array
    {
        $rows = [];

        foreach ($this->definitions() as $key => $definition) {
            $path = $definition['path'];
            $exists = is_file($path);
            $readable = $exists && is_readable($path);
            $writable = $exists && is_writable($path);
            $size = $exists ? (int) filesize($path) : 0;

            $rows[] = [
                'key' => $key,
                'label' => $definition['label'],
                'path' => $path,
                'group' => $definition['group'],
                'description' => $definition['description'],
                'exists' => $exists,
                'readable' => $readable,
                'writable' => $writable,
                'size_bytes' => $size,
                'size_label' => $this->formatBytes($size),
            ];
        }

        return $rows;
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     path: string,
     *     exists: bool,
     *     readable: bool,
     *     size_bytes: int,
     *     size_label: string,
     *     truncated: bool,
     *     content: string
     * }
     */
    public function readTail(string $key, int $maxBytes = 65536): array
    {
        $definitions = $this->definitions();

        if (! isset($definitions[$key])) {
            throw new RuntimeException(__('Unknown log target.'));
        }

        $definition = $definitions[$key];
        $path = $definition['path'];
        $exists = is_file($path);
        $readable = $exists && is_readable($path);
        $size = $exists ? (int) filesize($path) : 0;

        $base = [
            'key' => $key,
            'label' => $definition['label'],
            'path' => $path,
            'exists' => $exists,
            'readable' => $readable,
            'size_bytes' => $size,
            'size_label' => $this->formatBytes($size),
            'truncated' => false,
            'content' => '',
        ];

        if (! $readable || $size === 0) {
            return $base;
        }

        $maxBytes = max(1024, $maxBytes);
        $offset = max(0, $size - $maxBytes);
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return [...$base, 'readable' => false];
        }

        try {
            if ($offset > 0 && fseek($handle, $offset) !== 0) {
                return [...$base, 'readable' => false];
            }

            $raw = stream_get_contents($handle);

            if ($raw === false) {
                return [...$base, 'readable' => false];
            }
        } finally {
            fclose($handle);
        }

        if ($offset > 0) {
            $newline = strpos($raw, "\n");

            if ($newline !== false) {
                $raw = substr($raw, $newline + 1);
            }
        }

        $content = preg_replace("/[\x00-\x08\x0B\x0C\x0E-\x1F]/", '', $raw) ?? '';

        return [
            ...$base,
            'truncated' => $offset > 0,
            'content' => $content,
        ];
    }

    /**
     * @param  list<string>|null  $keys
     * @return array{cleared: list<string>, skipped: list<string>, failed: list<string>}
     */
    public function clear(?array $keys = null): array
    {
        $definitions = $this->definitions();
        $keys ??= array_keys(array_filter(
            $definitions,
            fn (array $definition): bool => $definition['group'] === 'app',
        ));

        $cleared = [];
        $skipped = [];
        $failed = [];

        foreach ($keys as $key) {
            if (! isset($definitions[$key])) {
                $skipped[] = $key;

                continue;
            }

            $path = $definitions[$key]['path'];

            if (! is_file($path) || ! is_writable($path)) {
                $skipped[] = $key;

                continue;
            }

            try {
                $this->truncateFile($path);
                $cleared[] = $key;
            } catch (\Throwable) {
                $failed[] = $key;
            }
        }

        return [
            'cleared' => $cleared,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }

    /**
     * @return array{cleared: list<string>, skipped: list<string>, failed: list<string>}
     */
    public function clearWritable(): array
    {
        $keys = [];

        foreach ($this->catalog() as $row) {
            if ($row['writable']) {
                $keys[] = $row['key'];
            }
        }

        return $this->clear($keys);
    }

    private function truncateFile(string $path): void
    {
        $handle = fopen($path, 'c+');

        if ($handle === false) {
            throw new RuntimeException(__('Unable to open :path', ['path' => $path]));
        }

        try {
            if (! ftruncate($handle, 0)) {
                throw new RuntimeException(__('Unable to truncate :path', ['path' => $path]));
            }

            rewind($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  list<string>  $paths
     */
    private function firstExistingPath(array $paths): ?string
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return $paths[0] ?? null;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return number_format($bytes / (1024 * 1024), 1).' MB';
    }
}
