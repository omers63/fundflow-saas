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
        return $this->templatedArrayPayload($notifiable);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $payload = $this->templatedArrayPayload($notifiable);

        return FilamentNotification::make()
            ->title((string) ($payload['title'] ?? $this->notificationTitle()))
            ->body((string) ($payload['body'] ?? ($this->senderName.': '.$this->preview)))
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

    /**
     * @return array<string, mixed>
     */
    protected function contentPayload(object $notifiable): array
    {
        return [
            'url' => $this->messagesUrl(),
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function templateVariables(object $notifiable): array
    {
        $subject = $this->notificationTitle();

        return [
            'sender_name' => $this->senderName,
            'preview' => $this->preview,
            'subject' => $subject,
            'title' => $subject,
            'action_url' => $this->messagesUrl(),
            'action_label' => __('Open messages'),
        ];
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
