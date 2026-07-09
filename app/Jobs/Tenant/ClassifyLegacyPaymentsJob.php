<?php

declare(strict_types=1);

namespace App\Jobs\Tenant;

use App\Filament\Support\RecipientDatabaseNotification;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\LegacyMigration\LegacyMigrationOrchestrator;
use App\Services\LegacyMigration\LegacyMigrationWorkingCopy;
use App\Services\LegacyMigration\LegacyPaymentClassifierService;
use App\Support\AssociativeCsv;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ClassifyLegacyPaymentsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 3600;

    /**
     * @param  array{
     *     cutoff_date?: string|null,
     *     default_password?: string|null,
     *     members_path: string,
     *     loans_path?: string|null,
     *     payments_path: string,
     *     strategy?: 'snapshot'|'historical',
     *     grace_cycles?: int|string|null,
     *     loan_funding_strategy?: string|null,
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
            if ($this->notifyUserId !== null) {
                $user = User::query()->find($this->notifyUserId);

                if ($user !== null) {
                    auth('tenant')->login($user);
                }
            }

            if (Storage::disk('local')->exists(LegacyPaymentClassifierService::CLASSIFIED_PAYMENTS_DISK_PATH)) {
                Storage::disk('local')->delete(LegacyPaymentClassifierService::CLASSIFIED_PAYMENTS_DISK_PATH);
            }

            $snapshot = $workingCopy->snapshot([
                'members' => $this->migrationOptions['members_path'] ?? null,
                'loans' => $this->migrationOptions['loans_path'] ?? null,
                'payments' => $this->migrationOptions['payments_path'] ?? null,
            ]);
            $existing = $workingCopy->existingPaths();

            $this->migrationOptions = [
                ...$this->migrationOptions,
                'members_path' => $snapshot['members_path']
                    ?? $existing['members_path']
                    ?? $this->migrationOptions['members_path']
                    ?? null,
                'loans_path' => $snapshot['loans_path']
                    ?? $existing['loans_path']
                    ?? $this->migrationOptions['loans_path']
                    ?? null,
                'payments_path' => $snapshot['payments_path']
                    ?? $existing['payments_path']
                    ?? $this->migrationOptions['payments_path']
                    ?? null,
            ];

            Setting::set('legacy_migration', 'classify_inputs', json_encode([
                'members_path' => $this->migrationOptions['members_path'] ?? null,
                'loans_path' => $this->migrationOptions['loans_path'] ?? null,
                'payments_path' => $this->migrationOptions['payments_path'] ?? null,
                'loans_header' => filled($this->migrationOptions['loans_path'] ?? null)
                    ? AssociativeCsv::headers((string) $this->migrationOptions['loans_path'])
                    : [],
            ], JSON_THROW_ON_ERROR));

            $result = $orchestrator->classifyAndPersistPayments($this->migrationOptions);
            Setting::set('legacy_migration', 'classify_errors', json_encode(
                array_slice($result['errors'] ?? [], 0, 10),
                JSON_THROW_ON_ERROR,
            ));
            Setting::set('legacy_migration', 'classify_status', 'completed');
            Setting::set('legacy_migration', 'classify_error', '');
        } catch (Throwable $exception) {
            report($exception);

            Setting::set('legacy_migration', 'classify_status', 'failed');
            Setting::set('legacy_migration', 'classify_error', $exception->getMessage());

            $this->notifyRequester(
                fn (Notification $notification): Notification => $notification
                    ->title(__('Classification failed'))
                    ->body($exception->getMessage()),
                'danger',
            );

            throw $exception;
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
