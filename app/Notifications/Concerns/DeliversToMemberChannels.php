<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Models\Tenant\User;
use App\Support\MemberLocale;
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
        return $this->withMemberLocale($notifiable, function () use ($notifiable): MailMessage {
            $payload = $this->toArray($notifiable);
            $title = (string) ($payload['title'] ?? '');
            $body = (string) ($payload['body'] ?? '');

            return (new MailMessage)
                ->subject($title)
                ->line($body);
        });
    }

    public function toSms(object $notifiable): string
    {
        return $this->withMemberLocale($notifiable, function () use ($notifiable): string {
            $payload = $this->toArray($notifiable);
            $title = (string) ($payload['title'] ?? '');
            $body = (string) ($payload['body'] ?? '');

            return trim($title.($body !== '' ? ': '.$body : ''));
        });
    }

    public function toWhatsApp(object $notifiable): string
    {
        return $this->toSms($notifiable);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    protected function withMemberLocale(object $notifiable, callable $callback): mixed
    {
        if ($notifiable instanceof User) {
            return MemberLocale::usingPreferred($notifiable, $callback);
        }

        return $callback();
    }
}
