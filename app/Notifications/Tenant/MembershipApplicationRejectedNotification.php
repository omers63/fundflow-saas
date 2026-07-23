<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\MembershipApplication;
use App\Notifications\Concerns\DeliversToMemberChannels;
use App\Services\Tenant\NotificationPreferenceService;
use Illuminate\Notifications\Notification;

class MembershipApplicationRejectedNotification extends Notification
{
    use DeliversToMemberChannels;

    public function __construct(
        public readonly MembershipApplication $application,
        public readonly ?string $reason = null,
    ) {}

    /**
     * @return list<string>
     */
    protected function memberNotificationSupportedChannels(): array
    {
        return [
            NotificationPreferenceService::CH_IN_APP,
            NotificationPreferenceService::CH_EMAIL,
        ];
    }

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
        return [
            'member_name' => $this->application->name,
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function templateVariables(object $notifiable): array
    {
        $body = filled($this->reason)
            ? $this->reason
            : __('Your membership application could not be approved at this time.');

        return [
            'member_name' => $this->application->name,
            'body' => $body,
            'title' => __('Membership application update'),
        ];
    }
}
