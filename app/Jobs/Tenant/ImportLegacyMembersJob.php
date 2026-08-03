<?php

declare(strict_types=1);

namespace App\Jobs\Tenant;

use App\Filament\Support\RecipientDatabaseNotification;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\LegacyMigration\IncompleteLegacyImportException;
use App\Services\LegacyMigration\LegacyImportFailureRecorder;
use App\Services\LegacyMigration\LegacyMigrationOrchestrator;
use App\Services\LegacyMigration\LegacyMigrationWorkingCopy;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;
use Throwable;

final class ImportLegacyMembersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 3600;

    /**
     * @param  array{
     *     cutoff_date?: string|null,
     *     default_password: string,
     *     members_path: string,
     * }  $migrationOptions
     */
    public function __construct(
        public array $migrationOptions,
        public ?string $cutoff = null,
        public ?int $notifyUserId = null,
    ) {}

    public function handle(
        LegacyMigrationOrchestrator $orchestrator,
        LegacyMigrationWorkingCopy $workingCopy,
    ): void {
        @set_time_limit(0);

        try {
            $this->authenticateRequester();

            $snapshot = $workingCopy->snapshot([
                'members' => $this->migrationOptions['members_path'] ?? null,
            ]);
            $existing = $workingCopy->existingPaths();

            $this->migrationOptions['members_path'] = $snapshot['members_path']
                ?? $existing['members_path']
                ?? $this->migrationOptions['members_path']
                ?? null;

            $result = $orchestrator->importMembers($this->migrationOptions, $this->cutoff);
            $summarized = LegacyMigrationOrchestrator::summarizeForDisplay($result);

            Setting::set('legacy_migration', 'members_imported', '1');
            Setting::set('legacy_migration', 'members_import_result', json_encode(
                $summarized,
                JSON_THROW_ON_ERROR,
            ));
            Setting::set('legacy_migration', 'members_import_status', 'completed');
            Setting::set('legacy_migration', 'members_import_error', '');
            Setting::set('legacy_migration', LegacyImportFailureRecorder::MEMBERS_FAILURE_SETTING, '');

            $members = $summarized['members'] ?? [];
            $failed = (int) ($members['failed'] ?? 0);
            $errorCount = count($members['errors'] ?? []);

            if ($failed > 0 || $errorCount > 0) {
                $this->notifyRequester(
                    fn (Notification $notification): Notification => $notification
                        ->title(__('Members imported with warnings'))
                        ->body(__('Members created: :created · Skipped: :skipped · Failed: :failed. Review row errors on the migration page.', [
                            'created' => $members['created'] ?? 0,
                            'skipped' => $members['skipped'] ?? 0,
                            'failed' => $failed,
                        ])),
                    'warning',
                );
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->recordFailure($exception);

            $this->notifyRequester(
                fn (Notification $notification): Notification => $notification
                    ->title(__('Import failed'))
                    ->body($this->notificationBody($exception)),
                'danger',
            );

            throw $exception;
        }
    }

    private function recordFailure(Throwable $exception): void
    {
        $result = $exception instanceof IncompleteLegacyImportException
            ? $exception->result
            : null;

        $stage = match (true) {
            $exception instanceof IncompleteLegacyImportException => $exception->stage,
            $exception instanceof AuthorizationException => 'authorization',
            $exception instanceof InvalidArgumentException => 'validation',
            default => 'import',
        };

        $payload = LegacyImportFailureRecorder::buildPayload(
            section: 'members',
            message: $exception->getMessage(),
            exception: $exception,
            result: $result,
            stage: $stage,
        );

        Setting::set('legacy_migration', 'members_import_status', 'failed');
        Setting::set('legacy_migration', 'members_import_error', $exception->getMessage());
        Setting::set(
            'legacy_migration',
            LegacyImportFailureRecorder::MEMBERS_FAILURE_SETTING,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );

        if ($result !== null) {
            Setting::set(
                'legacy_migration',
                'members_import_result',
                json_encode(
                    LegacyMigrationOrchestrator::summarizeForDisplay(['members' => $result]),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ),
            );
        }
    }

    private function notificationBody(Throwable $exception): string
    {
        $message = $exception->getMessage();

        if ($exception instanceof IncompleteLegacyImportException) {
            $failed = (int) ($exception->result['failed'] ?? 0);
            $errors = $exception->result['errors'] ?? [];
            $first = is_array($errors) && $errors !== [] ? (string) $errors[0] : '';

            return trim(__('Import incomplete (:failed row failure(s)). :message', [
                'failed' => $failed,
                'message' => $message,
            ]).(filled($first) ? "\n".$first : ''));
        }

        return $message !== '' ? $message : __('Import failed');
    }

    private function authenticateRequester(): void
    {
        if ($this->notifyUserId === null) {
            return;
        }

        $user = User::query()->find($this->notifyUserId);

        if ($user !== null) {
            auth('tenant')->login($user);
        }
    }

    /**
     * @param  callable(Notification): Notification  $configure
     */
    private function notifyRequester(callable $configure, string $color): void
    {
        if ($this->notifyUserId === null) {
            return;
        }

        $user = User::query()->find($this->notifyUserId);

        if ($user === null) {
            return;
        }

        RecipientDatabaseNotification::sendWithColor($user, $configure, $color);
    }
}
