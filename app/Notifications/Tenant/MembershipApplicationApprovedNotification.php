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
        return [
            'title' => __('Membership approved'),
            'body' => __('Welcome to the fund, :name. Your membership application has been approved.', [
                'name' => $this->member->name,
            ]),
            'member_name' => $this->member->name,
            'icon' => 'heroicon-o-check-badge',
            'color' => 'success',
        ];
    }
}
