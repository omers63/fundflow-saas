<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Support\MemberNotificationChannels;
use Illuminate\Notifications\Messages\MailMessage;

trait DeliversToMemberChannels
{
    /**
     * @return list<string|class-string>
     */
    public function via(object $notifiable): array
    {
        return MemberNotificationChannels::resolve($notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $payload = $this->toArray($notifiable);
        $title = (string) ($payload['title'] ?? '');
        $body = (string) ($payload['body'] ?? '');

        return (new MailMessage)
            ->subject($title)
            ->line($body);
    }

    public function toSms(object $notifiable): string
    {
        $payload = $this->toArray($notifiable);
        $title = (string) ($payload['title'] ?? '');
        $body = (string) ($payload['body'] ?? '');

        return trim($title.($body !== '' ? ': '.$body : ''));
    }

    public function toWhatsApp(object $notifiable): string
    {
        return $this->toSms($notifiable);
    }
}
