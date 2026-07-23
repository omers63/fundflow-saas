<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\Member;
use App\Notifications\Concerns\DeliversToMemberChannels;
use App\Services\Tenant\NotificationTemplateRenderer;
use App\Support\PublicPageSettings;
use App\Support\TenantAbsoluteUrl;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MemberOnboardingGreetingNotification extends Notification
{
    use DeliversToMemberChannels {
        via as protected deliversVia;
    }
    use Queueable;

    public function __construct(
        public readonly Member $member,
    ) {
    }

    /**
     * Always include email — this is the primary onboarding channel.
     *
     * @return list<string|class-string>
     */
    public function via(object $notifiable): array
    {
        $channels = $this->deliversVia($notifiable);

        if (!in_array('mail', $channels, true)) {
            $channels[] = 'mail';
        }

        return array_values($channels);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->withMemberLocale($notifiable, function () use ($notifiable): MailMessage {
            return app(NotificationTemplateRenderer::class)->brandedMailMessage(
                (string) $this->memberNotificationTemplateKey(),
                $this->memberNotificationLocale($notifiable),
                $this->resolvedTemplateVariables($notifiable),
                theme: 'onboarding',
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
        $fundName = PublicPageSettings::fundName();

        return FilamentNotification::make()
            ->title(__('Welcome to :fund', ['fund' => $fundName]))
            ->body(__('Your member portal is ready. Open it to check balances, contributions, and messages.'))
            ->icon('heroicon-o-sparkles')
            ->iconColor('success')
            ->actions([
                Action::make('open')
                    ->label(__('Open member portal'))
                    ->url($this->portalUrl())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    public function toSms(object $notifiable): string
    {
        return __('Welcome to :fund, :name. Open the member portal to get started: :url', [
            'fund' => PublicPageSettings::fundName(),
            'name' => $this->member->name,
            'url' => $this->portalUrl(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function contentPayload(object $notifiable): array
    {
        return [
            'url' => $this->portalUrl(),
            'member_name' => $this->member->name,
            'icon' => 'heroicon-o-sparkles',
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
            'fund_name' => PublicPageSettings::fundName(),
            'action_url' => $this->portalUrl(),
            'action_label' => __('Open member portal'),
        ];
    }

    protected function portalUrl(): string
    {
        $url = Filament::getPanel('member')?->getUrl() ?? '/member';

        return TenantAbsoluteUrl::resolve($url);
    }
}
