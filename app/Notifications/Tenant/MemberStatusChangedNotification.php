<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\Member;
use App\Notifications\Concerns\DeliversToMemberChannels;
use Illuminate\Notifications\Notification;

class MemberStatusChangedNotification extends Notification
{
    use DeliversToMemberChannels;

    public function __construct(
        public readonly Member $member,
        public readonly string $status,
        public readonly string $title,
        public readonly string $body,
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
        $color = match ($this->status) {
            'active' => 'success',
            'inactive' => 'warning',
            'withdrawn' => 'danger',
            default => 'gray',
        };

        return [
            'title' => $this->title,
            'body' => $this->body,
            'member_name' => $this->member->name,
            'icon' => 'heroicon-o-user-circle',
            'color' => $color,
        ];
    }
}
