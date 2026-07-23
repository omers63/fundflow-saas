<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\NotificationTemplate;
use App\Models\Tenant\User;
use App\Notifications\Concerns\DeliversToAdminChannels;
use App\Support\AdminNotificationChannels;
use App\Support\PushEventSettings;
use App\Support\TenantAbsoluteUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class DelinquencyDigestNotification extends Notification
{
    use DeliversToAdminChannels;

    /**
     * @param  array<string, int>  $counts
     */
    public function __construct(
        public array $counts,
        public string $delinquencyUrl,
    ) {}

    /**
     * @return list<string|class-string>
     */
    public function via(object $notifiable): array
    {
        $channels = PushEventSettings::filterChannels(
            AdminNotificationChannels::resolve(),
            $this->adminNotificationTemplateKey(),
        );

        if ($notifiable instanceof User && filled($notifiable->email)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->buildTemplatedAdminWebPush(
            $notifiable,
            $this->absoluteDelinquencyUrl(),
            'delinquency-digest',
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withRecipientLocale($notifiable, function () use ($notifiable): MailMessage {
            $copy = $this->adminTemplatedCopy($notifiable, NotificationTemplate::FAMILY_EMAIL);
            $guarantor = $this->counts['guarantor_at_risk'] ?? 0;

            $message = (new MailMessage)
                ->subject($copy['title'] !== '' ? $copy['title'] : __('Delinquency digest'))
                ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
                ->line(__('Delinquency activity needs your attention:'))
                ->line($copy['body'] !== '' ? $copy['body'] : $this->fallbackBody());

            if ($guarantor > 0) {
                $message->line(__(':guarantor loan(s) with guarantor exposure', ['guarantor' => $guarantor]));
            }

            return $message->action(__('Review in admin'), $this->absoluteDelinquencyUrl());
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->withRecipientLocale($notifiable, function () use ($notifiable): array {
            $copy = $this->adminBellCopy($notifiable);

            return FilamentNotification::make()
                ->title($copy['title'] !== '' ? $copy['title'] : __('Delinquency digest'))
                ->body($copy['body'] !== '' ? $copy['body'] : $this->fallbackBody())
                ->icon('heroicon-o-exclamation-triangle')
                ->iconColor('warning')
                ->actions([
                    Action::make('open')
                        ->label(__('Review in admin'))
                        ->url($this->absoluteDelinquencyUrl())
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
            'overdue' => (string) ($this->counts['overdue_installments'] ?? 0),
            'arrears' => (string) ($this->counts['contribution_arrears_periods'] ?? 0),
            'delinquent' => (string) ($this->counts['delinquent_members'] ?? 0),
            'guarantor' => (string) ($this->counts['guarantor_at_risk'] ?? 0),
            'action_url' => $this->absoluteDelinquencyUrl(),
            'action_label' => __('Review in admin'),
        ];
    }

    protected function fallbackBody(): string
    {
        return __(':overdue overdue installment(s) · :arrears contribution period(s) in arrears · :delinquent delinquent member(s).', [
            'overdue' => $this->counts['overdue_installments'] ?? 0,
            'arrears' => $this->counts['contribution_arrears_periods'] ?? 0,
            'delinquent' => $this->counts['delinquent_members'] ?? 0,
        ]);
    }

    protected function absoluteDelinquencyUrl(): string
    {
        return TenantAbsoluteUrl::resolve($this->delinquencyUrl);
    }
}
