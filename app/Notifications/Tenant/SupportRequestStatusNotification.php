<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\SupportRequest;
use App\Notifications\Concerns\DeliversToMemberChannels;
use Illuminate\Notifications\Notification;

class SupportRequestStatusNotification extends Notification
{
    use DeliversToMemberChannels;

    public function __construct(
        public readonly SupportRequest $supportRequest,
        public readonly string $status,
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
    protected function contentPayload(object $notifiable): array
    {
        $label = SupportRequest::statusOptions()[$this->status] ?? ucfirst($this->status);

        return [
            'title' => __('Support request updated'),
            'body' => __('Request #:id (“:subject”) is now :status.', [
                'id' => $this->supportRequest->id,
                'subject' => $this->supportRequest->subject,
                'status' => $label,
            ]),
            'icon' => 'heroicon-o-chat-bubble-left-right',
            'color' => in_array($this->status, [SupportRequest::STATUS_RESOLVED, SupportRequest::STATUS_CLOSED], true)
                ? 'success'
                : 'info',
        ];
    }
}
