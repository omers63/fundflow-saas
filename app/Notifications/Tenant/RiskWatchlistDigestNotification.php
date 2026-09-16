<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\User;
use App\Notifications\Concerns\DeliversToAdminChannels;
use App\Support\AdminNotificationChannels;
use App\Support\PushEventSettings;
use App\Support\TenantAbsoluteUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RiskWatchlistDigestNotification extends Notification
{
    use DeliversToAdminChannels;

    /**
     * @param  list<array{name: string, score: int, band: string}>  $rows
     */
    public function __construct(
        public array $rows,
        public string $membersUrl,
    ) {}

    /**
     * @return list<string|class-string>
     */
    public function via(object $notifiable): array
    {
        $channels = PushEventSettings::filterChannels(
            AdminNotificationChannels::resolve(),
            null,
        );

        if ($notifiable instanceof User && filled($notifiable->email)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('Risk watch-list digest'))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__(':count member(s) are high/critical risk:', ['count' => count($this->rows)]));

        foreach (array_slice($this->rows, 0, 15) as $row) {
            $message->line(sprintf('%s — %d (%s)', $row['name'], $row['score'], $row['band']));
        }

        return $message->action(__('Review members'), TenantAbsoluteUrl::resolve($this->membersUrl));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $preview = collect($this->rows)->take(5)
            ->map(fn (array $row): string => $row['name'].' '.$row['score'])
            ->implode(', ');

        return FilamentNotification::make()
            ->title(__('Risk watch-list digest'))
            ->body(__(':count high/critical — :preview', [
                'count' => count($this->rows),
                'preview' => $preview,
            ]))
            ->icon('heroicon-o-exclamation-triangle')
            ->iconColor('warning')
            ->actions([
                Action::make('view')
                    ->label(__('Review members'))
                    ->url(TenantAbsoluteUrl::resolve($this->membersUrl))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('Risk watch-list digest'),
            'count' => count($this->rows),
            'url' => $this->membersUrl,
        ];
    }
}
