<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Tenant\Pages\CommunicationsWorkspacePage;
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
        return $this->buildTemplatedAdminWebPush(
            $notifiable,
            $this->inboxUrl(),
            'admin-message-'.md5($this->memberName.$this->subject),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->withRecipientLocale($notifiable, function () use ($notifiable): array {
            $copy = $this->adminBellCopy($notifiable);

            return FilamentNotification::make()
                ->title($copy['title'] !== '' ? $copy['title'] : $this->fallbackTitle())
                ->body($copy['body'] !== '' ? $copy['body'] : $this->subject.': '.$this->preview)
                ->icon('heroicon-o-chat-bubble-left-right')
                ->iconColor('info')
                ->actions([
                    Action::make('open')
                        ->label(__('Open inbox'))
                        ->url($this->inboxUrl())
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
            'title' => $this->fallbackTitle(),
            'subject' => $this->subject,
            'preview' => $this->preview,
            'member_name' => $this->memberName,
            'action_url' => $this->inboxUrl(),
            'action_label' => __('Open inbox'),
        ];
    }

    protected function fallbackTitle(): string
    {
        return $this->isReply
            ? __('Reply from :name', ['name' => $this->memberName])
            : __('New message from :name', ['name' => $this->memberName]);
    }

    protected function inboxUrl(): string
    {
        $url = CommunicationsWorkspacePage::getUrl(
            ['sideTab' => 'inbox'],
            panel: 'tenant',
        );

        return TenantAbsoluteUrl::resolve($url);
    }
}
