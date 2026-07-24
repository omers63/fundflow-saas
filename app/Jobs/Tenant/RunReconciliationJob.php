<?php

declare(strict_types=1);

namespace App\Jobs\Tenant;

use App\Filament\Tenant\Pages\ReconciliationOverviewPage;
use App\Models\Tenant\ReconciliationSnapshot;
use App\Models\Tenant\User;
use App\Notifications\Tenant\ReconciliationRunCompletedNotification;
use App\Services\ReconciliationReportService;
use App\Services\ReconciliationService;
use App\Support\BusinessDay;
use App\Support\LocalizationSettings;
use App\Support\MemberLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class RunReconciliationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const MODE_EXCEPTION_QUEUE = 'exception_queue';

    public const UI_RUN_STATUS_COMPLETED = 'completed';

    public const UI_RUN_STATUS_FAILED = 'failed';

    public int $timeout = 600;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $mode,
        public array $options = [],
        public ?int $notifyUserId = null,
        public ?string $uiRunToken = null,
    ) {
    }

    public static function uiRunCacheKey(string $token): string
    {
        return 'reconciliation:ui_run:' . $token;
    }

    public static function uiRunStatus(mixed $cached): ?string
    {
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        if (is_array($cached) && isset($cached['status']) && is_string($cached['status'])) {
            return $cached['status'];
        }

        return null;
    }

    /**
     * @return array{title: string, body: string, color: string}|null
     */
    public static function uiRunToast(mixed $cached): ?array
    {
        if (!is_array($cached)) {
            return null;
        }

        $title = $cached['title'] ?? null;
        $body = $cached['body'] ?? null;
        $color = $cached['color'] ?? null;

        if (!is_string($title) || $title === '' || !is_string($body) || !is_string($color)) {
            return null;
        }

        return [
            'title' => $title,
            'body' => $body,
            'color' => $color,
        ];
    }

    public function handle(
        ReconciliationReportService $reports,
        ReconciliationService $reconciliation,
    ): void {
        @set_time_limit(0);

        $failed = false;
        $toastTitle = '';
        $toastBody = '';
        $toastColor = 'success';

        try {
            if ($this->mode === self::MODE_EXCEPTION_QUEUE) {
                $result = $reconciliation->runNightlyBatch();

                $message = $this->localizeForRequester(function () use ($result): array {
                    return [
                        'title' => $result['halted']
                            ? __('Reconciliation halted')
                            : __('Reconciliation complete'),
                        'body' => __('Raised: :raised | Resolved: :resolved', [
                            'raised' => $result['raised'],
                            'resolved' => $result['resolved'],
                        ]),
                        'color' => $result['halted'] ? 'danger' : 'success',
                        'critical' => (bool) $result['halted'],
                    ];
                });

                $toastTitle = $message['title'];
                $toastBody = $message['body'];
                $toastColor = $message['color'];

                $this->notifyRequester(
                    $message['title'],
                    $message['body'],
                    $message['critical'],
                );

                return;
            }

            $now = BusinessDay::now();

            if ($this->mode === ReconciliationSnapshot::MODE_REALTIME) {
                $report = $reports->buildReport($this->mode, $now, null, null, $this->options);
            } elseif ($this->mode === ReconciliationSnapshot::MODE_DAILY) {
                $periodStart = $now->copy()->subDay()->startOfDay();
                $periodEnd = $now->copy()->subDay()->endOfDay();
                $report = $reports->buildReport($this->mode, $now, $periodStart, $periodEnd, $this->options);
            } elseif ($this->mode === ReconciliationSnapshot::MODE_MONTHLY) {
                $anchor = $now->copy()->subMonthNoOverflow();
                $periodStart = $anchor->copy()->startOfMonth();
                $periodEnd = $anchor->copy()->endOfMonth();
                $report = $reports->buildReport($this->mode, $now, $periodStart, $periodEnd, $this->options);
            } else {
                throw new \InvalidArgumentException('Unsupported reconciliation mode: ' . $this->mode);
            }

            $snapshot = $reports->persistSnapshot(
                $report,
                is_int($this->notifyUserId) ? $this->notifyUserId : null,
            );

            $pass = $report['verdict']['pass'] ?? false;

            $message = $this->localizeForRequester(function () use ($pass, $report, $snapshot): array {
                return [
                    'title' => $pass
                        ? __('Reconciliation passed')
                        : __('Reconciliation found critical issues'),
                    'body' => __('Snapshot #:id — critical: :critical, warnings: :warnings', [
                        'id' => $snapshot->id,
                        'critical' => ($report['verdict']['critical_issues'] ?? 0),
                        'warnings' => ($report['verdict']['warnings'] ?? 0),
                    ]),
                    'color' => $pass ? 'success' : 'danger',
                    'critical' => !$pass,
                ];
            });

            $toastTitle = $message['title'];
            $toastBody = $message['body'];
            $toastColor = $message['color'];

            $this->notifyRequester(
                $message['title'],
                $message['body'],
                $message['critical'],
            );
        } catch (Throwable $exception) {
            $failed = true;
            report($exception);

            $message = $this->localizeForRequester(function () use ($exception): array {
                return [
                    'title' => __('Reconciliation run failed'),
                    'body' => $exception->getMessage(),
                    'color' => 'danger',
                    'critical' => true,
                ];
            });

            $toastTitle = $message['title'];
            $toastBody = $message['body'];
            $toastColor = $message['color'];

            $this->notifyRequester(
                $message['title'],
                $message['body'],
                $message['critical'],
                mode: 'failed',
            );

            throw $exception;
        } finally {
            $this->markUiRunFinished($failed, $toastTitle, $toastBody, $toastColor);
        }
    }

    private function markUiRunFinished(bool $failed, string $title, string $body, string $color): void
    {
        if ($this->uiRunToken === null || $this->uiRunToken === '') {
            return;
        }

        Cache::put(
            self::uiRunCacheKey($this->uiRunToken),
            [
                'status' => $failed ? self::UI_RUN_STATUS_FAILED : self::UI_RUN_STATUS_COMPLETED,
                'title' => $title,
                'body' => $body,
                'color' => $color,
            ],
            now()->addHour(),
        );
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function localizeForRequester(callable $callback): mixed
    {
        $user = is_int($this->notifyUserId)
            ? User::query()->find($this->notifyUserId)
            : null;

        if ($user !== null) {
            return MemberLocale::usingPreferred($user, $callback);
        }

        $previous = app()->getLocale();
        app()->setLocale(LocalizationSettings::adminLocale());

        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }

    private function notifyRequester(string $title, string $summary, bool $critical, ?string $mode = null): void
    {
        if ($this->notifyUserId === null) {
            return;
        }

        $user = User::query()->find($this->notifyUserId);

        if ($user === null) {
            return;
        }

        $sideTab = ($mode ?? $this->mode) === self::MODE_EXCEPTION_QUEUE
            ? 'exceptions'
            : 'snapshots';

        $user->notify(new ReconciliationRunCompletedNotification(
            mode: $mode ?? $this->mode,
            title: $title,
            summary: $summary,
            reconciliationUrl: ReconciliationOverviewPage::getUrl(['sideTab' => $sideTab], panel: 'tenant'),
            critical: $critical,
        ));
    }
}
