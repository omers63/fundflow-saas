<?php

declare(strict_types=1);

namespace App\Jobs\Tenant;

use App\Filament\Support\RecipientDatabaseNotification;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\LegacyMigration\LegacyMigrationOrchestrator;
use App\Services\LegacyMigration\LegacyMigrationWorkingCopy;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

            Setting::set('legacy_migration', 'loans_imported', '1');
            Setting::set('legacy_migration', 'loans_import_result', json_encode(
                LegacyMigrationOrchestrator::summarizeForDisplay($result),
                JSON_THROW_ON_ERROR,
            ));
            Setting::set('legacy_migration', 'loans_import_status', 'completed');
            Setting::set('legacy_migration', 'loans_import_error', '');
        } catch (Throwable $exception) {
            report($exception);

            Setting::set('legacy_migration', 'loans_import_status', 'failed');
            Setting::set('legacy_migration', 'loans_import_error', $exception->getMessage());

            $this->notifyRequester(
                fn (Notification $notification): Notification => $notification
                    ->title(__('Import failed'))
                    ->body($exception->getMessage()),
                'danger',
            );

            throw $exception;
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
