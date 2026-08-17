<?php

declare(strict_types=1);

namespace App\Jobs\Tenant;

use App\Filament\Support\RecipientDatabaseNotification;
use App\Models\Tenant\User;
use App\Services\BusinessDayWindowRollbackService;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;
use Throwable;

final class BusinessDayWindowRollbackJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 3600;

    /**
     * @param  list<string>|null  $selectedKeys  Null undoes every window event.
     */
    public function __construct(
        public string $asOfDate,
        public ?array $selectedKeys = null,
        public ?int $notifyUserId = null,
    ) {}

    public function handle(BusinessDayWindowRollbackService $rollback): void
    {
        @set_time_limit(0);

        try {
            $report = $rollback->execute(
                Carbon::parse($this->asOfDate)->startOfDay(),
                $this->selectedKeys,
            );

            $this->notifyRequester(
                fn (Notification $notification): Notification => $notification
                    ->title(__('Business-day window undone'))
                    ->body($report->summary()),
                'success',
            );
        } catch (InvalidArgumentException $exception) {
            $this->notifyRequester(
                fn (Notification $notification): Notification => $notification
                    ->title(__('Rollback blocked'))
                    ->body($exception->getMessage()),
                'danger',
            );
        } catch (Throwable $exception) {
            report($exception);

            $this->notifyRequester(
                fn (Notification $notification): Notification => $notification
                    ->title(__('Rollback failed'))
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
