<?php

declare(strict_types=1);

namespace App\Jobs\Tenant;

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

            Setting::set('legacy_migration', 'members_imported', '1');
            Setting::set('legacy_migration', 'members_import_result', json_encode(
                LegacyMigrationOrchestrator::summarizeForDisplay($result),
                JSON_THROW_ON_ERROR,
            ));
            Setting::set('legacy_migration', 'members_import_status', 'completed');
            Setting::set('legacy_migration', 'members_import_error', '');
        } catch (Throwable $exception) {
            report($exception);

            Setting::set('legacy_migration', 'members_import_status', 'failed');
            Setting::set('legacy_migration', 'members_import_error', $exception->getMessage());

            $this->notifyRequester(__('Import failed'), $exception->getMessage(), 'danger');

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

    private function notifyRequester(string $title, string $body, string $color): void
    {
        if ($this->notifyUserId === null) {
            return;
        }

        $user = User::query()->find($this->notifyUserId);

        if ($user === null) {
            return;
        }

        $notification = Notification::make()->title($title)->body($body);

        match ($color) {
            'success' => $notification->success(),
            'danger' => $notification->danger(),
            default => $notification,
        };

        $notification->sendToDatabase($user);
    }
}
