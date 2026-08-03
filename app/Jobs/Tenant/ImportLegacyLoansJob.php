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

final class ImportLegacyLoansJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 3600;

    /**
     * @param  array{
     *     loans_path: string,
     *     strategy?: 'snapshot'|'historical',
     *     grace_cycles?: int|string|null,
     *     loan_funding_strategy?: string|null,
     *     payments_path?: string|null,
     *     skip_settlement_threshold?: bool|string|int|null,
     * }  $migrationOptions
     */
    public function __construct(
        public array $migrationOptions,
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
                'loans' => $this->migrationOptions['loans_path'] ?? null,
                'payments' => $this->migrationOptions['payments_path'] ?? null,
            ]);
            $existing = $workingCopy->existingPaths();

            $this->migrationOptions['loans_path'] = $snapshot['loans_path']
                ?? $existing['loans_path']
                ?? $this->migrationOptions['loans_path']
                ?? null;
            $this->migrationOptions['payments_path'] = $snapshot['payments_path']
                ?? $existing['payments_path']
                ?? $this->migrationOptions['payments_path']
                ?? null;

            $result = $orchestrator->importLoans($this->migrationOptions);
            $summarized = LegacyMigrationOrchestrator::summarizeForDisplay($result);

            Setting::set('legacy_migration', 'loans_imported', '1');
            Setting::set('legacy_migration', 'loans_import_result', json_encode(
                $summarized,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ));
            Setting::set('legacy_migration', 'loans_import_status', 'completed');
            Setting::set('legacy_migration', 'loans_import_error', '');
            Setting::set('legacy_migration', LegacyImportFailureRecorder::LOANS_FAILURE_SETTING, '');

            $loans = $summarized['loans'] ?? [];
            $failed = (int) ($loans['failed'] ?? 0);
            $errorCount = count($loans['errors'] ?? []);

            if ($failed > 0 || $errorCount > 0) {
                $this->notifyRequester(
                    fn (Notification $notification): Notification => $notification
                        ->title(__('Loans imported with warnings'))
                        ->body(__('Loans created: :loans · Failed: :failed. Review row errors on the migration page.', [
                            'loans' => $loans['created'] ?? 0,
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
                    ->body($exception->getMessage() !== '' ? $exception->getMessage() : __('Import failed')),
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
            section: 'loans',
            message: $exception->getMessage(),
            exception: $exception,
            result: $result,
            stage: $stage,
        );

        Setting::set('legacy_migration', 'loans_import_status', 'failed');
        Setting::set('legacy_migration', 'loans_import_error', $exception->getMessage());
        Setting::set(
            'legacy_migration',
            LegacyImportFailureRecorder::LOANS_FAILURE_SETTING,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );

        if ($result !== null) {
            Setting::set(
                'legacy_migration',
                'loans_import_result',
                json_encode(
                    LegacyMigrationOrchestrator::summarizeForDisplay(['loans' => $result]),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ),
            );
        }
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
