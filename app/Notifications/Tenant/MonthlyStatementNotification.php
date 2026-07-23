<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\MonthlyStatement;
use App\Notifications\Concerns\DeliversToMemberChannels;
use App\Services\Tenant\MonthlyStatementPdfService;
use App\Services\Tenant\NotificationPreferenceService;
use App\Services\Tenant\NotificationTemplateRenderer;
use App\Support\PushEventSettings;
use App\Support\StatementSettings;
use App\Support\TenantAbsoluteUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MonthlyStatementNotification extends Notification
{
    use DeliversToMemberChannels;
    use Queueable;

    public const DELIVERY_DEFAULT = 'default';

    public const DELIVERY_NOTIFY = 'notify';

    public const DELIVERY_EMAIL = 'email';

    public function __construct(
        public MonthlyStatement $statement,
        public string $delivery = self::DELIVERY_DEFAULT,
    ) {}

    /**
     * @return list<string|class-string>
     */
    public function via(object $notifiable): array
    {
        $supported = match ($this->delivery) {
            self::DELIVERY_EMAIL => [NotificationPreferenceService::CH_EMAIL],
            self::DELIVERY_NOTIFY => [
                NotificationPreferenceService::CH_IN_APP,
                NotificationPreferenceService::CH_PUSH,
            ],
            default => StatementSettings::autoEmail()
                ? NotificationPreferenceService::CATEGORIES[NotificationPreferenceService::STATEMENTS]['supported']
                : [
                    NotificationPreferenceService::CH_IN_APP,
                    NotificationPreferenceService::CH_PUSH,
                ],
        };

        return PushEventSettings::filterChannels(
            NotificationPreferenceService::resolve(
                $notifiable,
                NotificationPreferenceService::STATEMENTS,
                $supported,
            ),
            $this->memberNotificationTemplateKey(),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withMemberLocale($notifiable, function () use ($notifiable): MailMessage {
            $mail = app(NotificationTemplateRenderer::class)->brandedMailMessage(
                (string) $this->memberNotificationTemplateKey(),
                $this->memberNotificationLocale($notifiable),
                $this->resolvedTemplateVariables($notifiable),
            );

            if (! StatementSettings::attachPdf()) {
                return $mail;
            }

            $pdf = app(MonthlyStatementPdfService::class);

            return $mail->attachData(
                $pdf->binary($this->statement),
                $pdf->filename($this->statement),
                ['mime' => 'application/pdf'],
            );
        });
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
    public function toDatabase(object $notifiable): array
    {
        $payload = $this->templatedArrayPayload($notifiable);
        $url = $this->statementUrl();

        return FilamentNotification::make()
            ->title((string) ($payload['title'] ?? __('Monthly statement ready')))
            ->body((string) ($payload['body'] ?? ''))
            ->icon('heroicon-o-document-text')
            ->iconColor('info')
            ->actions([
                Action::make('download')
                    ->label(__('Download statement'))
                    ->url($url)
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    /**
     * @return array<string, mixed>
     */
    protected function contentPayload(object $notifiable): array
    {
        return [
            'url' => $this->statementUrl(),
            'period' => $this->statement->period_formatted,
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function templateVariables(object $notifiable): array
    {
        return [
            'period' => (string) $this->statement->period_formatted,
            'action_url' => $this->statementUrl(),
            'action_label' => __('Download statement'),
        ];
    }

    protected function statementUrl(): string
    {
        return TenantAbsoluteUrl::resolve(route('tenant.member.statement.pdf', $this->statement));
    }
}
