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
        $this->fundPosting->loadMissing('member');

        return $this->buildAdminWebPushFor(
            $notifiable,
            __('New deposit request'),
            FundPostingNotificationFormatter::adminNewRequestPlainText($this->fundPosting),
            $this->reviewUrl(),
            'fund-posting-'.$this->fundPosting->getKey(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title(__('New deposit request'))
            ->body(FundPostingNotificationFormatter::adminNewRequestBody($this->fundPosting))
            ->icon('heroicon-o-inbox-arrow-down')
            ->iconColor('warning')
            ->actions([
                Action::make('review')
                    ->label(__('Review deposit'))
                    ->url($this->reviewUrl())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    protected function reviewUrl(): string
    {
        $url = FundPostingResource::listUrl([
            'status' => ['value' => 'pending'],
        ]);

        return TenantAbsoluteUrl::resolve($url);
    }
}
