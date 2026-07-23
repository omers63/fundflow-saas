<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Tenant\Resources\FundPostings\FundPostingResource;
use App\Models\Tenant\FundPosting;
use App\Notifications\Concerns\DeliversToAdminChannels;
use App\Notifications\Tenant\Concerns\BuildsFundPostingDatabaseMessage;
use App\Support\Notifications\FundPostingNotificationFormatter;
use App\Support\TenantAbsoluteUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class NewFundPostingNotification extends Notification
{
    use BuildsFundPostingDatabaseMessage;
    use DeliversToAdminChannels;

    public function __construct(
        public FundPosting $fundPosting,
    ) {}

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->buildTemplatedAdminWebPush(
            $notifiable,
            $this->reviewUrl(),
            'fund-posting-'.$this->fundPosting->getKey(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->withRecipientLocale($notifiable, function () use ($notifiable): array {
            $copy = $this->adminBellCopy($notifiable);

            return FilamentNotification::make()
                ->title($copy['title'] !== '' ? $copy['title'] : __('New deposit request'))
                ->body($copy['body'] !== '' ? $copy['body'] : FundPostingNotificationFormatter::adminNewRequestBody($this->fundPosting))
                ->icon('heroicon-o-inbox-arrow-down')
                ->iconColor('warning')
                ->actions([
                    Action::make('review')
                        ->label(__('Review deposit'))
                        ->url($this->reviewUrl())
                        ->markAsRead(),
                ])
                ->getDatabaseMessage();
        });
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function adminTemplateVariables(object $notifiable): array
    {
        $this->fundPosting->loadMissing('member');

        return [
            'member_name' => (string) ($this->fundPosting->member?->name ?? __('Member')),
            'amount' => number_format((float) $this->fundPosting->amount, 2),
            'body' => FundPostingNotificationFormatter::adminNewRequestPlainText($this->fundPosting),
            'action_url' => $this->reviewUrl(),
            'action_label' => __('Review deposit'),
        ];
    }

    protected function reviewUrl(): string
    {
        $url = FundPostingResource::listUrl([
            'status' => ['value' => 'pending'],
        ]);

        return TenantAbsoluteUrl::resolve($url);
    }
}
