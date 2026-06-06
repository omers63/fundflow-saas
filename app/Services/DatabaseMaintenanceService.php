<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\DatabaseBackup;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DatabaseMaintenanceService
{
    /**
     * Never truncated: migrations, auth/session/cache queues, Spatie permission tables.
     *
     * @return list<string>
     */
    public function alwaysExcludedTableNames(): array
    {
        $permissionTables = array_values(array_unique(array_filter([
            config('permission.table_names.permissions'),
            config('permission.table_names.roles'),
            config('permission.table_names.model_has_permissions'),
            config('permission.table_names.model_has_roles'),
            config('permission.table_names.role_has_permissions'),
        ])));

        return array_values(array_unique(array_merge([
            'migrations',
            'users',
            'password_reset_tokens',
            'sessions',
            'cache',
            'cache_locks',
            'jobs',
            'job_batches',
            'failed_jobs',
        ], $permissionTables)));
    }

    /**
     * @return list<string>
     */
    private function databaseTableNames(): array
    {
        return array_values(array_unique(
            Schema::getTableListing(schema: null, schemaQualified: false)
        ));
    }

    /**
     * @return list<string>
     */
    public function getPurgeableTables(): array
    {
        $excluded = $this->alwaysExcludedTableNames();
        $names = $this->databaseTableNames();

        $purgeable = [];
        foreach ($names as $table) {
            if (in_array($table, $excluded, true)) {
                continue;
            }
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }
            $purgeable[] = $table;
        }
        $purgeable = array_values(array_unique($purgeable));
        sort($purgeable);

        return $purgeable;
    }

    /**
     * @return list<string>
     */
    public function getTablesSkippedForSoftDeletes(): array
    {
        $excluded = $this->alwaysExcludedTableNames();
        $names = $this->databaseTableNames();
        $skipped = [];

        foreach ($names as $table) {
            if (in_array($table, $excluded, true)) {
                continue;
            }
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (Schema::hasColumn($table, 'deleted_at')) {
                $skipped[] = $table;
            }
        }
        $skipped = array_values(array_unique($skipped));
        sort($skipped);

        return $skipped;
    }

    public function purgePurgeableTables(): int
    {
        $tables = $this->getPurgeableTables();
        if ($tables === []) {
            return 0;
        }

        Schema::disableForeignKeyConstraints();

        try {
            foreach ($tables as $table) {
                DB::table($table)->truncate();
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        return count($tables);
    }

    public function downloadBackupResponse(): BinaryFileResponse|StreamedResponse
    {
        $connection = Config::get('database.default');
        $driver = Config::get("database.connections.{$connection}.driver");

        return match ($driver) {
            'sqlite' => $this->downloadSqliteBackup(),
            'mysql', 'mariadb' => $this->downloadMysqlBackup($connection),
            default => throw new RuntimeException(
                "Database backup is not implemented for driver [{$driver}]. Use SQLite or MySQL."
            ),
        };
    }

    public function createStoredBackup(): DatabaseBackup
    {
        $this->ensureBackupDirectoryExists();

        $connection = Config::get('database.default');
        $driver = Config::get("database.connections.{$connection}.driver");

        $extension = match ($driver) {
            'sqlite' => '.sqlite',
            'mysql', 'mariadb' => '.sql',
            default => throw new RuntimeException(
                "Database backup is not implemented for driver [{$driver}]. Use SQLite or MySQL."
            ),
        };

        $filename = 'fundflow-backup-'.now()->format('Y-m-d-His').$extension;
        $relativePath = $this->backupDirectoryRelative().'/'.$filename;
        $fullPath = storage_path('app/'.$relativePath);

        try {
            match ($driver) {
                'sqlite' => $this->copySqliteDatabaseToFile($fullPath),
                'mysql', 'mariadb' => $this->writeMysqlDumpToFile($connection, $fullPath),
                default => throw new RuntimeException(
                    "Database backup is not implemented for driver [{$driver}]. Use SQLite or MySQL."
                ),
            };
        } catch (\Throwable $e) {
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
            throw $e;
        }

        if (! is_file($fullPath)) {
            throw new RuntimeException('Backup file was not created.');
        }

        $size = filesize($fullPath);
        if ($size === false) {
            @unlink($fullPath);
            throw new RuntimeException('Could not read backup file size.');
        }

        return DatabaseBackup::query()->create([
            'path' => $relativePath,
            'filename' => $filename,
            'size_bytes' => $size,
            'driver' => $driver === 'mariadb' ? 'mariadb' : $driver,
            'user_id' => auth('tenant')->id(),
        ]);
    }

    public function deleteStoredBackup(DatabaseBackup $backup): void
    {
        if (Storage::disk('local')->exists($backup->path)) {
            Storage::disk('local')->delete($backup->path);
        }
        $backup->delete();
    }

    public function backupDirectoryRelative(): string
    {
        $tenantId = tenant('id');

        return $tenantId !== null
            ? "backups/tenants/{$tenantId}"
            : 'backups';
    }

    public function ensureBackupDirectoryExists(): void
    {
        Storage::disk('local')->makeDirectory($this->backupDirectoryRelative());
    }

    /**
     * @return array{
     *     driver: string,
     *     connection: string,
     *     display_name: string,
     *     path_or_schema: string|null,
     *     size_bytes: int|null,
     *     modified_at: CarbonInterface|null,
     *     table_count: int,
     *     stored_backup_count: int,
     *     stored_backups_total_bytes: int,
     *     backup_folder_total_bytes: int,
     * }
     */
    public function getBackupOverviewStats(): array
    {
        $connectionName = Config::get('database.default');
        $driver = Config::get("database.connections.{$connectionName}.driver");

        $displayName = '—';
        $pathOrSchema = null;
        $sizeBytes = null;
        $modifiedAt = null;

        if ($driver === 'sqlite') {
            try {
                $sqlitePath = $this->resolveSqliteDatabasePath();
                $displayName = basename($sqlitePath);
                $pathOrSchema = $sqlitePath;
                if (is_file($sqlitePath)) {
                    $sizeBytes = filesize($sqlitePath) ?: null;
                    $modifiedAt = Carbon::createFromTimestamp(filemtime($sqlitePath));
                }
            } catch (RuntimeException) {
                $displayName = '(sqlite path unavailable)';
            }
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            $config = Config::get("database.connections.{$connectionName}");
            $database = (string) ($config['database'] ?? '');
            $displayName = $database !== '' ? $database : '—';
            $pathOrSchema = $database !== '' ? $database : null;
            if ($database !== '') {
                $row = DB::selectOne(
                    'SELECT SUM(data_length + index_length) AS total FROM information_schema.tables WHERE table_schema = ?',
                    [$database]
                );
                $sizeBytes = (int) ($row->total ?? 0);
            }
        }

        $backupDir = storage_path('app/'.$this->backupDirectoryRelative());
        $folderTotal = 0;
        if (is_dir($backupDir)) {
            foreach (glob($backupDir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                if (is_file($file)) {
                    $folderTotal += (int) filesize($file);
                }
            }
        }

        return [
            'driver' => is_string($driver) ? $driver : 'unknown',
            'connection' => is_string($connectionName) ? $connectionName : 'default',
            'display_name' => $displayName,
            'path_or_schema' => $pathOrSchema,
            'size_bytes' => $sizeBytes,
            'modified_at' => $modifiedAt,
            'table_count' => count($this->databaseTableNames()),
            'stored_backup_count' => (int) DatabaseBackup::query()->count(),
            'stored_backups_total_bytes' => (int) DatabaseBackup::query()->sum('size_bytes'),
            'backup_folder_total_bytes' => $folderTotal,
        ];
    }

    private function downloadSqliteBackup(): BinaryFileResponse
    {
        $path = $this->resolveSqliteDatabasePath();
        $filename = 'fundflow-backup-'.now()->format('Y-m-d-His').'.sqlite';

        return response()->download($path, $filename, [
            'Content-Type' => 'application/octet-stream',
        ]);
    }

    private function downloadMysqlBackup(string $connection): StreamedResponse
    {
        $filename = 'fundflow-backup-'.now()->format('Y-m-d-His').'.sql';

        return response()->streamDownload(function () use ($connection): void {
            echo $this->runMysqlDump($connection);
        }, $filename, [
            'Content-Type' => 'application/sql',
        ]);
    }

    private function resolveSqliteDatabasePath(): string
    {
        $path = Config::get('database.connections.sqlite.database');

        if ($path === ':memory:') {
            throw new RuntimeException('Cannot use an in-memory SQLite database for this operation.');
        }

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('SQLite database path is not configured.');
        }

        if (! str_starts_with($path, DIRECTORY_SEPARATOR) && ! preg_match('/^[A-Za-z]:\\\\/', $path)) {
            $path = base_path($path);
        }

        $resolved = realpath($path) ?: $path;

        if (! is_file($resolved) || ! is_readable($resolved)) {
            throw new RuntimeException('SQLite database file is missing or not readable.');
        }

        return $resolved;
    }

    private function copySqliteDatabaseToFile(string $destinationAbsolutePath): void
    {
        $source = $this->resolveSqliteDatabasePath();
        if (! @copy($source, $destinationAbsolutePath)) {
            throw new RuntimeException('Failed to copy SQLite database to backup path.');
        }
    }

    private function writeMysqlDumpToFile(string $connection, string $destinationAbsolutePath): void
    {
        $sql = $this->runMysqlDump($connection);
        if (file_put_contents($destinationAbsolutePath, $sql) === false) {
            throw new RuntimeException('Failed to write mysqldump output to backup file.');
        }
    }

    private function runMysqlDump(string $connection): string
    {
        $config = Config::get("database.connections.{$connection}");

        $host = $config['host'] ?? '127.0.0.1';
        $port = (string) ($config['port'] ?? 3306);
        $database = $config['database'] ?? '';
        $username = $config['username'] ?? 'root';
        $password = $config['password'] ?? '';

        if ($database === '') {
            throw new RuntimeException('MySQL database name is not configured.');
        }

        $mysqldump = $this->findMysqldumpBinary();
        if ($mysqldump === null) {
            throw new RuntimeException(
                'The mysqldump executable was not found in your PATH. Install MySQL client tools or use SQLite for one-click backup.'
            );
        }

        $result = Process::env(['MYSQL_PWD' => $password])
            ->timeout(600)
            ->run([
                $mysqldump,
                '--host='.$host,
                '--port='.$port,
                '--user='.$username,
                '--single-transaction',
                '--no-tablespaces',
                '--routines',
                '--add-drop-table',
                $database,
            ]);

        if (! $result->successful()) {
            throw new RuntimeException(
                'mysqldump failed: '.($result->errorOutput() ?: $result->output())
            );
        }

        return $result->output();
    }

    private function findMysqldumpBinary(): ?string
    {
        $candidates = PHP_OS_FAMILY === 'Windows'
            ? ['mysqldump.exe', 'mysqldump']
            : ['mysqldump'];

        $paths = explode(PATH_SEPARATOR, getenv('PATH') ?: '');

        foreach ($candidates as $name) {
            foreach ($paths as $dir) {
                if ($dir === '') {
                    continue;
                }
                $full = rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name;
                if (is_file($full) && is_executable($full)) {
                    return $full;
                }
            }
        }

        foreach ($candidates as $name) {
            $which = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
            $check = Process::run([$which, $name]);
            if ($check->successful()) {
                $line = trim(explode("\n", $check->output())[0] ?? '');
                if ($line !== '' && is_file($line)) {
                    return $line;
                }
            }
        }

        return null;
    }
}
