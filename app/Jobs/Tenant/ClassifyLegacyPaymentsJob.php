<?php

declare(strict_types=1);

namespace App\Jobs\Tenant;

use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\LegacyMigration\LegacyPaymentClassifierService;
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

    public function __construct(
        public string $paymentsPath,
        public ?string $cutoffDate,
        public ?string $membersPath,
        public ?string $loansPath,
        public ?int $notifyUserId = null,
    ) {}

    public function handle(LegacyPaymentClassifierService $classifier): void
    {
        @set_time_limit(0);

        try {
            $cutoff = filled($this->cutoffDate) ? now()->parse($this->cutoffDate) : null;

            $result = $classifier->classifyFile(
                $this->paymentsPath,
                $cutoff,
                $this->membersPath,
                $this->loansPath,
            );

            if ($result['rows'] !== []) {
                $classifier->writeClassifiedCsv(
                    Storage::disk('local')->path(LegacyPaymentClassifierService::CLASSIFIED_PAYMENTS_DISK_PATH),
                    $result['rows'],
                );
            }

            Setting::set('legacy_migration', 'classify_stats', json_encode($result['stats'], JSON_THROW_ON_ERROR));
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
                __('Classification failed'),
                $exception->getMessage(),
                'danger',
            );

            throw $exception;
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
            'warning' => $notification->warning(),
            'danger' => $notification->danger(),
            default => $notification,
        };

        $notification->sendToDatabase($user);
    }
}
