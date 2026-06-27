<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Filament\Member\Resources\MyFundPostings\MyFundPostingResource;
use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Models\Tenant\User;
use App\Support\MemberLocale;
use App\Support\MemberNotificationChannels;
use App\Support\NotificationPlainText;
use App\Support\TenantAbsoluteUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

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

            return trim($title . ($body !== '' ? ': ' . $body : ''));
        });
    }

    public function toWhatsApp(object $notifiable): string
    {
        return $this->toSms($notifiable);
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->withMemberLocale($notifiable, function () use ($notifiable): WebPushMessage {
            $payload = $this->toArray($notifiable);
            $title = NotificationPlainText::from((string) ($payload['title'] ?? ''));
            $body = NotificationPlainText::from((string) ($payload['body'] ?? ''));

            $message = (new WebPushMessage)
                ->title($title)
                ->body($body)
                ->icon('/icons/icon-192x192.png')
                ->badge('/icons/icon-192x192.png')
                ->options(['TTL' => 86400]);

            if (isset($payload['loan_id'])) {
                $message
                    ->tag('member-loan-' . $payload['loan_id'])
                    ->data(['url' => $this->memberLoanUrl((int) $payload['loan_id'])]);
            } elseif (isset($payload['fund_posting_id'])) {
                $message
                    ->tag('member-fund-posting-' . $payload['fund_posting_id'])
                    ->data(['url' => $this->memberFundPostingsUrl()]);
            } elseif (isset($payload['url'])) {
                $message->data(['url' => (string) $payload['url']]);
            }

            return $message;
        });
    }

    protected function memberLoanUrl(int $loanId): string
    {
        $url = MyLoanResource::getUrl(
            'view',
            ['record' => $loanId],
            panel: 'member',
        );

        return TenantAbsoluteUrl::resolve($url);
    }

    protected function memberFundPostingsUrl(): string
    {
        $url = MyFundPostingResource::getUrl('index', panel: 'member');

        return TenantAbsoluteUrl::resolve($url);
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
