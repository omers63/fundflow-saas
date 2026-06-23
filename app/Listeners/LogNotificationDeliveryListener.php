<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Tenant\NotificationLog;
use App\Models\Tenant\User;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Channels\WhatsAppChannel;
use App\Support\MemberLocale;
use App\Support\SystemLoggingSettings;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Schema;
use Throwable;

class LogNotificationDeliveryListener
{
    public function handleSent(NotificationSent $event): void
    {
        if (! SystemLoggingSettings::notificationLogEnabled() || ! Schema::hasTable('notification_logs')) {
            return;
        }

        $this->persist($event->notifiable, $event->notification, $event->channel, 'sent');
    }

    public function handleFailed(NotificationFailed $event): void
    {
        if (! SystemLoggingSettings::notificationLogEnabled() || ! Schema::hasTable('notification_logs')) {
            return;
        }

        $error = $event->data;
        $errorMessage = $error instanceof Throwable
            ? $error->getMessage()
            : (is_string($error) ? $error : json_encode($error, JSON_UNESCAPED_UNICODE));

        $this->persist(
            $event->notifiable,
            $event->notification,
            $event->channel,
            'failed',
            is_string($errorMessage) ? $errorMessage : null,
        );
    }

    private function persist(
        mixed $notifiable,
        Notification $notification,
        string $channel,
        string $status,
        ?string $errorMessage = null,
    ): void {
        if (! $notifiable instanceof Model && ! $notifiable instanceof Authenticatable) {
            return;
        }

        $userId = $notifiable instanceof User ? $notifiable->id : null;
        [$subject, $body] = $notifiable instanceof User
            ? MemberLocale::using($notifiable, fn (): array => $this->extractContent($notification, $notifiable, $channel))
            : $this->extractContent($notification, $notifiable, $channel);

        NotificationLog::query()->create([
            'user_id' => $userId,
            'channel' => $this->normalizeChannel($channel),
            'subject' => $subject,
            'body' => $body !== '' ? $body : __('(No message body)'),
            'status' => $status,
            'error_message' => $errorMessage,
            'sent_at' => now(),
        ]);
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function extractContent(Notification $notification, object $notifiable, string $channel): array
    {
        if ($channel === 'mail' && method_exists($notification, 'toMail')) {
            $mail = $notification->toMail($notifiable);
            $lines = array_filter([
                ...($mail->introLines ?? []),
                ...($mail->outroLines ?? []),
            ]);

            return [
                $mail->subject ?? null,
                strip_tags(implode("\n", $lines)),
            ];
        }

        if ($channel === 'database') {
            if (method_exists($notification, 'toDatabase')) {
                $data = $notification->toDatabase($notifiable);

                return [
                    is_array($data) ? ($data['title'] ?? null) : null,
                    is_array($data) ? (string) ($data['body'] ?? '') : '',
                ];
            }

            if (method_exists($notification, 'toArray')) {
                $data = $notification->toArray($notifiable);

                return [
                    is_array($data) ? ($data['title'] ?? null) : null,
                    is_array($data) ? (string) ($data['body'] ?? json_encode($data, JSON_UNESCAPED_UNICODE)) : '',
                ];
            }
        }

        if ($channel === SmsChannel::class && method_exists($notification, 'toSms')) {
            return [null, (string) $notification->toSms($notifiable)];
        }

        if ($channel === WhatsAppChannel::class && method_exists($notification, 'toWhatsApp')) {
            return [null, (string) $notification->toWhatsApp($notifiable)];
        }

        return [class_basename($notification), class_basename($notification)];
    }

    private function normalizeChannel(string $channel): string
    {
        return match ($channel) {
            'mail' => 'mail',
            'database' => 'database',
            SmsChannel::class => 'twilio',
            WhatsAppChannel::class => 'whatsapp',
            default => str_contains($channel, '\\') ? class_basename($channel) : $channel,
        };
    }
}
