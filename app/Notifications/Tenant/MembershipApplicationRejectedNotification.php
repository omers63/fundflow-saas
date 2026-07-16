<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\MembershipApplication;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MembershipApplicationRejectedNotification extends Notification
{
    public function __construct(
        public readonly MembershipApplication $application,
        public readonly ?string $reason = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $body = filled($this->reason)
            ? $this->reason
            : __('Your membership application could not be approved at this time.');

        return (new MailMessage)
            ->subject(__('Membership application update'))
            ->greeting(__('Hello :name,', ['name' => $this->application->name]))
            ->line($body)
            ->line(__('If you have questions, contact the fund administrator.'));
    }
}
