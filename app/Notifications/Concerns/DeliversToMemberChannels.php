<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Filament\Member\Resources\MyFundPostings\MyFundPostingResource;
use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Models\Tenant\NotificationTemplate;
use App\Models\Tenant\User;
use App\Services\Tenant\NotificationPreferenceService;
use App\Services\Tenant\NotificationTemplateRenderer;
use App\Support\MemberLocale;
use App\Support\MemberNotificationChannels;
use App\Support\NotificationPlainText;
use App\Support\NotificationTemplateCatalog;
use App\Support\PushEventSettings;
use App\Support\TenantAbsoluteUrl;
use App\Support\WebPushNotification;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

trait DeliversToMemberChannels
{
    private bool $resolvingNotificationContentPayload = false;

    /**
     * @return list<string|class-string>
     */
    public function via(object $notifiable): array
    {
        $category = $this->memberNotificationCategory();

        $channels = $category === null
            ? MemberNotificationChannels::resolve($notifiable)
            : NotificationPreferenceService::resolve(
                $notifiable,
                $category,
                $this->memberNotificationSupportedChannels(),
            );

        return PushEventSettings::filterChannels(
            $channels,
            $this->memberNotificationTemplateKey(),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withMemberLocale($notifiable, function () use ($notifiable): MailMessage {
            $key = $this->memberNotificationTemplateKey();

            if ($key !== null) {
                return app(NotificationTemplateRenderer::class)->brandedMailMessage(
                    $key,
                    $this->memberNotificationLocale($notifiable),
                    $this->resolvedTemplateVariables($notifiable),
                );
            }

            $payload = $this->toArray($notifiable);
            $title = (string) ($payload['title'] ?? '');
            $body = (string) ($payload['body'] ?? '');

            return (new MailMessage)
                ->subject($title)
                ->line($body);
        });
    }

    /**
     * Default Filament bell payload from the in-app (FAMILY_IN_APP) template.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->memberBellDatabaseMessage($notifiable);
    }

    /**
     * @return array<string, mixed>
     */
    protected function memberBellDatabaseMessage(object $notifiable): array
    {
        $payload = $this->templatedArrayPayload($notifiable);

        $notification = FilamentNotification::make()
            ->title((string) ($payload['title'] ?? ''))
            ->body((string) ($payload['body'] ?? ''))
            ->icon((string) ($payload['icon'] ?? 'heroicon-o-bell'))
            ->iconColor((string) ($payload['color'] ?? $payload['iconColor'] ?? 'info'));

        $url = isset($payload['url']) ? trim((string) $payload['url']) : '';

        if ($url !== '') {
            $notification->actions([
                Action::make('view')
                    ->label((string) ($payload['action_label'] ?? __('Open')))
                    ->url($url)
                    ->markAsRead(),
            ]);
        }

        return $notification->getDatabaseMessage();
    }

    public function toSms(object $notifiable): string
    {
        return $this->withMemberLocale($notifiable, function () use ($notifiable): string {
            $key = $this->memberNotificationTemplateKey();

            if ($key !== null) {
                return app(NotificationTemplateRenderer::class)->plainText(
                    $key,
                    NotificationTemplate::FAMILY_SMS_PUSH,
                    $this->memberNotificationLocale($notifiable),
                    $this->resolvedTemplateVariables($notifiable),
                );
            }

            $payload = $this->toArray($notifiable);
            $title = (string) ($payload['title'] ?? '');
            $body = (string) ($payload['body'] ?? '');

            return trim($title.($body !== '' ? ': '.$body : ''));
        });
    }

    public function toWhatsApp(object $notifiable): string
    {
        return $this->toSms($notifiable);
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->withMemberLocale($notifiable, function () use ($notifiable): WebPushMessage {
            $key = $this->memberNotificationTemplateKey();
            $meta = $this->notificationContentPayload($notifiable);

            if ($key !== null) {
                $rendered = app(NotificationTemplateRenderer::class)->render(
                    $key,
                    NotificationTemplate::FAMILY_SMS_PUSH,
                    $this->memberNotificationLocale($notifiable),
                    $this->resolvedTemplateVariables($notifiable),
                );
                $title = NotificationPlainText::from($rendered['subject']);
                $body = NotificationPlainText::from($rendered['body']);
            } else {
                $title = NotificationPlainText::from((string) ($meta['title'] ?? ''));
                $body = NotificationPlainText::from((string) ($meta['body'] ?? ''));
            }

            $memberName = $this->pushRecipientName($notifiable, $meta);

            if ($memberName !== '') {
                $title = $memberName.' — '.$title;
            }

            $message = (new WebPushMessage)
                ->title($title)
                ->body($body)
                ->icon(WebPushNotification::ICON_PATH)
                ->badge(WebPushNotification::BADGE_PATH)
                ->options(['TTL' => 86400]);

            if (isset($meta['loan_id'])) {
                $message
                    ->tag('member-loan-'.$meta['loan_id'])
                    ->data(['url' => $this->memberLoanUrl((int) $meta['loan_id'])]);
            } elseif (isset($meta['fund_posting_id'])) {
                $message
                    ->tag('member-fund-posting-'.$meta['fund_posting_id'])
                    ->data(['url' => $this->memberFundPostingsUrl()]);
            } elseif (isset($meta['url'])) {
                $message->data(['url' => (string) $meta['url']]);
            }

            return $message;
        });
    }

    protected function memberNotificationCategory(): ?string
    {
        return NotificationTemplateCatalog::categoryFor(static::class);
    }

    /**
     * @return list<string>
     */
    protected function memberNotificationSupportedChannels(): array
    {
        return NotificationTemplateCatalog::supportedChannelsFor(static::class);
    }

    protected function memberNotificationTemplateKey(): ?string
    {
        return NotificationTemplateCatalog::keyFor(static::class);
    }

    protected function memberNotificationLocale(object $notifiable): string
    {
        if ($notifiable instanceof User) {
            return $notifiable->preferredLocale();
        }

        return app()->getLocale();
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function resolvedTemplateVariables(object $notifiable): array
    {
        if (method_exists($this, 'templateVariables')) {
            /** @var array<string, scalar|null> $variables */
            $variables = $this->templateVariables($notifiable);

            return $variables;
        }

        $payload = $this->notificationContentPayload($notifiable);

        return [
            'member_name' => (string) ($payload['member_name'] ?? $this->pushRecipientName($notifiable, $payload)),
            'title' => (string) ($payload['title'] ?? ''),
            'body' => (string) ($payload['body'] ?? ''),
            'subject' => (string) ($payload['title'] ?? ''),
            'action_url' => isset($payload['url']) ? (string) $payload['url'] : null,
            'action_label' => __('Open'),
            'loan_id' => isset($payload['loan_id']) ? (string) $payload['loan_id'] : null,
            'amount' => isset($payload['amount']) ? (string) $payload['amount'] : null,
            'period' => isset($payload['period']) ? (string) $payload['period'] : null,
        ];
    }

    /**
     * Content before template rendering (avoids recursion when toArray uses templates).
     *
     * @return array<string, mixed>
     */
    protected function notificationContentPayload(object $notifiable): array
    {
        if (method_exists($this, 'contentPayload')) {
            /** @var array<string, mixed> $payload */
            $payload = $this->contentPayload($notifiable);

            return $payload;
        }

        if ($this->resolvingNotificationContentPayload) {
            return [];
        }

        $this->resolvingNotificationContentPayload = true;

        try {
            return $this->toArray($notifiable);
        } finally {
            $this->resolvingNotificationContentPayload = false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function templatedArrayPayload(object $notifiable): array
    {
        $base = method_exists($this, 'contentPayload')
            ? $this->contentPayload($notifiable)
            : $this->notificationContentPayload($notifiable);

        $key = $this->memberNotificationTemplateKey();

        if ($key === null) {
            return $base;
        }

        $rendered = app(NotificationTemplateRenderer::class)->render(
            $key,
            NotificationTemplate::FAMILY_IN_APP,
            $this->memberNotificationLocale($notifiable),
            $this->resolvedTemplateVariables($notifiable),
        );

        return array_merge($base, [
            'title' => $rendered['subject'],
            'body' => $rendered['body'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function pushRecipientName(object $notifiable, array $payload): string
    {
        if (filled($payload['member_name'] ?? null)) {
            return trim((string) $payload['member_name']);
        }

        if ($notifiable instanceof User) {
            $member = $notifiable->relationLoaded('member')
                ? $notifiable->member
                : $notifiable->activeMember();

            $fromMember = trim((string) ($member?->name ?? ''));
            if ($fromMember !== '') {
                return $fromMember;
            }

            return trim((string) ($notifiable->name ?? ''));
        }

        return '';
    }

    protected function memberLoanUrl(int $loanId): string
    {
        $url = MyLoanResource::getUrl(
            'view',
            ['record' => $loanId],
            panel: 'member',
        );

        return TenantAbsoluteUrl::resolve($url);
    }

    protected function memberFundPostingsUrl(): string
    {
        $url = MyFundPostingResource::getUrl('index', panel: 'member');

        return TenantAbsoluteUrl::resolve($url);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    protected function withMemberLocale(object $notifiable, callable $callback): mixed
    {
        if ($notifiable instanceof User) {
            return MemberLocale::usingPreferred($notifiable, $callback);
        }

        return $callback();
    }
}
