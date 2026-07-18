<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\MonthlyStatement;
use App\Notifications\Concerns\DeliversToMemberChannels;
use App\Support\MemberNotificationChannels;
use App\Support\StatementSettings;
use App\Support\TenantAbsoluteUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MonthlyStatementNotification extends Notification
{
    use DeliversToMemberChannels;
    use Queueable;

    public const DELIVERY_DEFAULT = 'default';

    public const DELIVERY_NOTIFY = 'notify';

    public const DELIVERY_EMAIL = 'email';

    public function __construct(
        public MonthlyStatement $statement,
        public string $delivery = self::DELIVERY_DEFAULT,
    ) {}

    /**
     * @return list<string|class-string>
     */
    public function via(object $notifiable): array
    {
        $channels = MemberNotificationChannels::resolve($notifiable);

        if ($this->delivery === self::DELIVERY_EMAIL) {
            return in_array('mail', $channels, true) ? ['mail'] : [];
        }

        if ($this->delivery === self::DELIVERY_NOTIFY) {
            return array_values(array_filter(
                $channels,
                fn (string $channel): bool => $channel !== 'mail',
            ));
        }

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
        return $this->withMemberLocale($notifiable, function (): MailMessage {
            $url = TenantAbsoluteUrl::resolve(route('tenant.member.statement.pdf', $this->statement));

            return (new MailMessage)
                ->subject(__('Monthly statement ready'))
                ->line(__('Your statement for :period is available to download.', [
                    'period' => $this->statement->period_formatted,
                ]))
                ->action(__('Download statement'), $url);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('Monthly statement ready'),
            'body' => __('Your statement for :period is available to download.', [
                'period' => $this->statement->period_formatted,
            ]),
            'url' => TenantAbsoluteUrl::resolve(route('tenant.member.statement.pdf', $this->statement)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title(__('Monthly statement ready'))
            ->body(__('Your statement for :period is available to download.', [
                'period' => $this->statement->period_formatted,
            ]))
            ->icon('heroicon-o-document-text')
            ->iconColor('info')
            ->actions([
                Action::make('download')
                    ->label(__('Download statement'))
                    ->url(TenantAbsoluteUrl::resolve(route('tenant.member.statement.pdf', $this->statement)))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
