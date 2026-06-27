<?php

declare(strict_types=1);

namespace App\Jobs\Tenant;

use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\LegacyMigration\LegacyMigrationOrchestrator;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class RunLegacyMigrationPaymentsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 3600;

    /**
     * @param  array{
     *     strategy?: 'snapshot'|'historical',
     *     payments_path?: string|null,
     *     classified_payments_path?: string|null,
     * }  $options
     * @param  list<string>  $uploadPathsToDelete
     */
    public function __construct(
        public array $options,
        public array $uploadPathsToDelete = [],
        public ?int $notifyUserId = null,
    ) {}

    public function handle(LegacyMigrationOrchestrator $orchestrator): void
    {
        try {
            $this->actAsImportingAdmin();

            $payments = $orchestrator->applyClassifiedPayments($this->options);

            $lastRunJson = Setting::get('legacy_migration', 'last_run');
            $lastRun = is_string($lastRunJson) ? json_decode($lastRunJson, true) : [];
            $lastRun = is_array($lastRun) ? $lastRun : [];

            if ($payments !== null) {
                $lastRun['payments'] = $payments;
            }

            $summarized = LegacyMigrationOrchestrator::summarizeForDisplay($lastRun);

            Setting::set('legacy_migration', 'last_run', json_encode($summarized, JSON_UNESCAPED_UNICODE));
            Setting::set('legacy_migration', 'run_status', 'completed');

            $members = $summarized['members'] ?? [];

            $this->notifyRequester(
                __('Migration complete'),
                __('Members created: :created · Payment contributions: :contributions · Loan repayments: :repayments · Reclassified: :reclassified', [
                    'created' => $members['created'] ?? 0,
                    'contributions' => $payments['contributions'] ?? 0,
                    'repayments' => $payments['loan_repayments'] ?? 0,
                    'reclassified' => $payments['reclassified_as_contribution'] ?? 0,
                ]),
                'success',
            );
        } catch (Throwable $exception) {
            report($exception);

            Setting::set('legacy_migration', 'run_status', 'failed');
            Setting::set('legacy_migration', 'last_error', $exception->getMessage());

            $this->notifyRequester(
                __('Payment import failed'),
                $exception->getMessage(),
                'danger',
            );

            throw $exception;
        } finally {
            foreach ($this->uploadPathsToDelete as $relative) {
                if (str_starts_with($relative, 'legacy-migration/working/')) {
                    continue;
                }

                try {
                    Storage::disk('local')->delete($relative);
                } catch (Throwable) {
                }
            }
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

        $notification = Notification::make()
            ->title($title)
            ->body($body);

        match ($color) {
            'success' => $notification->success(),
            'danger' => $notification->danger(),
            default => $notification,
        };

        $notification->sendToDatabase($user);
    }

    /**
     * @throws AuthorizationException
     */
    private function actAsImportingAdmin(): void
    {
        if ($this->notifyUserId === null) {
            throw new AuthorizationException(__('You must be signed in to import members.'));
        }

        $user = User::query()->find($this->notifyUserId);

        if ($user === null || ! $user->is_admin) {
            throw new AuthorizationException(__('You do not have permission to import members.'));
        }

        auth('tenant')->onceUsingId($this->notifyUserId);
    }
}
