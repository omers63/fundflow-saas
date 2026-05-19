<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\MonthlyStatement;
use App\Support\MemberNotificationChannels;
use App\Support\StatementSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MonthlyStatementNotification extends Notification
{
    use Queueable;

    public function __construct(
        public MonthlyStatement $statement,
    ) {}

    /**
     * @return list<string|class-string>
     */
    public function via(object $notifiable): array
    {
        $channels = MemberNotificationChannels::resolve($notifiable);

        if (! StatementSettings::autoEmail()) {
            return array_values(array_filter(
                $channels,
                fn (string $channel): bool => $channel !== 'mail',
            ));
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('tenant.member.statement.pdf', $this->statement);

        return (new MailMessage)
            ->subject(__('Monthly statement ready'))
            ->line(__('Your statement for :period is available to download.', [
                'period' => $this->statement->period_formatted,
            ]))
            ->action(__('Download statement'), $url);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => __('Monthly statement ready'),
            'body' => __('Your statement for :period is available to download.', [
                'period' => $this->statement->period_formatted,
            ]),
        ];
    }
}
