<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Models\Tenant\User;
use App\Services\TwilioMessagingService;
use App\Support\MemberNotificationChannels;
use App\Support\NotificationSettings;
use Illuminate\Notifications\Notification;

class SmsChannel
{
    public function __construct(
        private TwilioMessagingService $twilio,
    ) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! NotificationSettings::smsEnabled() || ! $notifiable instanceof User) {
            return;
        }

        $phone = MemberNotificationChannels::phoneFor($notifiable);

        if ($phone === null) {
            return;
        }

        $message = method_exists($notification, 'toSms')
            ? $notification->toSms($notifiable)
            : null;

        if (! filled($message)) {
            return;
        }

        $this->twilio->sendSms($phone, $message);
    }
}
