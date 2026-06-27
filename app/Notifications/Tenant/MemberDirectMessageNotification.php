<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Member\Pages\CommunicationsPage;
use App\Notifications\Concerns\DeliversToMemberChannels;
use App\Support\TenantAbsoluteUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class MemberDirectMessageNotification extends Notification
{
    use DeliversToMemberChannels;

    public function __construct(
        public string $senderName,
        public string $preview,
        public ?string $subject = null,
        public ?string $title = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->notificationTitle(),
            'body' => $this->senderName.': '.$this->preview,
            'url' => $this->messagesUrl(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->notificationTitle())
            ->body($this->senderName.': '.$this->preview)
            ->icon('heroicon-o-chat-bubble-left-right')
            ->iconColor('info')
            ->actions([
                Action::make('open')
                    ->label(__('Open messages'))
                    ->url($this->messagesUrl())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    protected function notificationTitle(): string
    {
        if ($this->title !== null) {
            return $this->title;
        }

        return filled($this->subject)
            ? $this->subject
            : __('Message from Administration');
    }

    protected function messagesUrl(): string
    {
        $url = CommunicationsPage::getUrl(['tab' => 'messages'], panel: 'member');

        return TenantAbsoluteUrl::resolve($url);
    }
}
