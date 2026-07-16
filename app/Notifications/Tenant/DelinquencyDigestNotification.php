<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\User;
use App\Notifications\Concerns\DeliversToAdminChannels;
use App\Support\AdminNotificationChannels;
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
        $channels = AdminNotificationChannels::resolve();

        if ($notifiable instanceof User && filled($notifiable->email)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $overdue = $this->counts['overdue_installments'] ?? 0;
        $arrears = $this->counts['contribution_arrears_periods'] ?? 0;
        $delinquent = $this->counts['delinquent_members'] ?? 0;

        return $this->buildAdminWebPushFor(
            $notifiable,
            __('Delinquency digest'),
            __(':overdue overdue installment(s) · :arrears contribution period(s) in arrears · :delinquent delinquent member(s).', [
                'overdue' => $overdue,
                'arrears' => $arrears,
                'delinquent' => $delinquent,
            ]),
            $this->absoluteDelinquencyUrl(),
            'delinquency-digest',
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $overdue = $this->counts['overdue_installments'] ?? 0;
        $arrears = $this->counts['contribution_arrears_periods'] ?? 0;
        $delinquent = $this->counts['delinquent_members'] ?? 0;
        $guarantor = $this->counts['guarantor_at_risk'] ?? 0;

        $message = (new MailMessage)
            ->subject(__('Delinquency digest'))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('Delinquency activity needs your attention:'))
            ->line(__(':overdue overdue installment(s)', ['overdue' => $overdue]))
            ->line(__(':arrears contribution period(s) in arrears', ['arrears' => $arrears]))
            ->line(__(':delinquent delinquent member(s)', ['delinquent' => $delinquent]));

        if ($guarantor > 0) {
            $message->line(__(':guarantor loan(s) with guarantor exposure', ['guarantor' => $guarantor]));
        }

        return $message->action(__('Review in admin'), $this->absoluteDelinquencyUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $overdue = $this->counts['overdue_installments'] ?? 0;
        $arrears = $this->counts['contribution_arrears_periods'] ?? 0;
        $delinquent = $this->counts['delinquent_members'] ?? 0;

        return FilamentNotification::make()
            ->title(__('Delinquency digest'))
            ->body(__(':overdue overdue installment(s) · :arrears contribution period(s) in arrears · :delinquent delinquent member(s). Review loans, contributions, or members as needed.', [
                'overdue' => $overdue,
                'arrears' => $arrears,
                'delinquent' => $delinquent,
            ]))
            ->icon('heroicon-o-exclamation-triangle')
            ->iconColor('warning')
            ->actions([
                Action::make('open')
                    ->label(__('Review in admin'))
                    ->url($this->absoluteDelinquencyUrl())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    protected function absoluteDelinquencyUrl(): string
    {
        return TenantAbsoluteUrl::resolve($this->delinquencyUrl);
    }
}
