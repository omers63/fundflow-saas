<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Notifications\Concerns\DeliversToMemberChannels;
use Illuminate\Notifications\Notification;

class MembershipApplicationApprovedNotification extends Notification
{
    use DeliversToMemberChannels;

    public function __construct(
        public readonly MembershipApplication $application,
        public readonly Member $member,
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
        return [
            'member_name' => $this->member->name,
            'icon' => 'heroicon-o-check-badge',
            'color' => 'success',
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function templateVariables(object $notifiable): array
    {
        return [
            'member_name' => $this->member->name,
            'body' => __('Welcome to the fund, :name. Your membership application has been approved.', [
                'name' => $this->member->name,
            ]),
            'title' => __('Membership approved'),
        ];
    }
}
