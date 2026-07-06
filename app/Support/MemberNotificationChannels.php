<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\User;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Channels\WhatsAppChannel;
use NotificationChannels\WebPush\WebPushChannel;

final class MemberNotificationChannels
{
    /**
     * @return list<string|class-string>
     */
    public static function resolve(object $notifiable): array
    {
        $channels = [];

        if (CommunicationSettings::inAppEnabled()) {
            $channels[] = 'database';
        }

        if ($notifiable instanceof User && WebPushNotification::enabled()) {
            $channels[] = WebPushChannel::class;
        }

        if (! $notifiable instanceof User) {
            return $channels;
        }

        if (CommunicationSettings::emailEnabled() && self::emailFor($notifiable) !== null) {
            $channels[] = 'mail';
        }

        $phone = self::phoneFor($notifiable);

        if ($phone === null) {
            return $channels;
        }

        if (NotificationSettings::smsEnabled() && NotificationSettings::twilioConfigured()) {
            $channels[] = SmsChannel::class;
        }

        if (NotificationSettings::whatsappEnabled() && NotificationSettings::twilioConfigured()) {
            $channels[] = WhatsAppChannel::class;
        }

        return $channels;
    }

    public static function emailFor(User $user): ?string
    {
        return app(MemberUserEmail::class)->deliverableEmailFor($user);
    }

    public static function phoneFor(User $user): ?string
    {
        $user->loadMissing('member');
        $phone = trim((string) ($user->phone ?? $user->member?->phone ?? ''));

        if ($phone === '') {
            return null;
        }

        return $phone;
    }
}
