<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Member\Pages\CommunicationsPage;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Concerns\DeliversToMemberChannels;
use App\Support\TenantAbsoluteUrl;
use App\Support\WebPushNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;

class MemberAnnouncementNotification extends Notification
{
    use DeliversToMemberChannels;
    use Queueable;

    public function __construct(
        public string $title,
        public string $body,
        public bool $sendEmail = true,
        public bool $sendSms = false,
    ) {}

    /**
     * @return list<string|class-string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];

        if ($this->sendEmail) {
            $channels[] = 'mail';
        }

        if ($this->sendSms) {
            $channels[] = SmsChannel::class;
        }

        if (WebPushNotification::enabled()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'url' => TenantAbsoluteUrl::resolve(
                CommunicationsPage::getUrl(['tab' => 'messages'], panel: 'member'),
            ),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withMemberLocale($notifiable, fn (): MailMessage => (new MailMessage)
            ->subject($this->title)
            ->line($this->body));
    }

    public function toSms(object $notifiable): string
    {
        return $this->withMemberLocale($notifiable, fn (): string => trim($this->title.($this->body !== '' ? ': '.$this->body : '')));
    }
}
