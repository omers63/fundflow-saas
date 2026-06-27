<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Tenant\Pages\MessagesInboxPage;
use App\Notifications\Concerns\DeliversToAdminChannels;
use App\Support\TenantAbsoluteUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class AdminDirectMessageNotification extends Notification
{
    use DeliversToAdminChannels;

    public function __construct(
        public string $memberName,
        public string $subject,
        public string $preview,
        public bool $isReply = false,
    ) {}

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->buildAdminWebPush(
            $this->title(),
            $this->subject.': '.$this->preview,
            $this->inboxUrl(),
            'admin-message-'.md5($this->memberName.$this->subject),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->title())
            ->body($this->subject.': '.$this->preview)
            ->icon('heroicon-o-chat-bubble-left-right')
            ->iconColor('info')
            ->actions([
                Action::make('open')
                    ->label(__('Open inbox'))
                    ->url($this->inboxUrl())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    protected function title(): string
    {
        return $this->isReply
            ? __('Reply from :name', ['name' => $this->memberName])
            : __('New message from :name', ['name' => $this->memberName]);
    }

    protected function inboxUrl(): string
    {
        $url = MessagesInboxPage::getUrl(panel: 'tenant');

        return TenantAbsoluteUrl::resolve($url);
    }
}
