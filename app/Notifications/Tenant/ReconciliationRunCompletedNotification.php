<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Jobs\Tenant\RunReconciliationJob;
use App\Models\Tenant\ReconciliationSnapshot;
use App\Notifications\Concerns\DeliversToAdminChannels;
use App\Support\TenantAbsoluteUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class ReconciliationRunCompletedNotification extends Notification
{
    use DeliversToAdminChannels;

    /**
     * @param  string  $mode  realtime|daily|monthly|exception_queue|failed
     */
    public function __construct(
        public string $mode,
        public string $title,
        public string $summary,
        public string $reconciliationUrl,
        public bool $critical = false,
    ) {}

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->buildAdminWebPushFor(
            $notifiable,
            $this->title,
            $this->summary,
            $this->absoluteUrl(),
            'reconciliation-run-'.$this->mode,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->withRecipientLocale($notifiable, function () use ($notifiable): array {
            $copy = $this->adminBellCopy($notifiable);

            return FilamentNotification::make()
                ->title($copy['title'] !== '' ? $copy['title'] : $this->title)
                ->body($copy['body'] !== '' ? $copy['body'] : $this->summary)
                ->icon($this->critical ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-shield-check')
                ->iconColor($this->critical ? 'danger' : 'success')
                ->actions([
                    Action::make('review')
                        ->label(__('Review reconciliation'))
                        ->url($this->absoluteUrl())
                        ->markAsRead(),
                ])
                ->getDatabaseMessage();
        });
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function adminTemplateVariables(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'summary' => $this->summary,
            'mode' => $this->modeLabel(),
            'action_url' => $this->absoluteUrl(),
            'action_label' => __('Review reconciliation'),
        ];
    }

    protected function modeLabel(): string
    {
        return match ($this->mode) {
            ReconciliationSnapshot::MODE_REALTIME => __('Real-time check'),
            ReconciliationSnapshot::MODE_DAILY => __('Daily snapshot'),
            ReconciliationSnapshot::MODE_MONTHLY => __('Monthly snapshot'),
            RunReconciliationJob::MODE_EXCEPTION_QUEUE => __('Exception queue re-check'),
            'failed' => __('Reconciliation run failed'),
            default => __('Reconciliation'),
        };
    }

    protected function absoluteUrl(): string
    {
        return TenantAbsoluteUrl::resolve($this->reconciliationUrl);
    }
}
