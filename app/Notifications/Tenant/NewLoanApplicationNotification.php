<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Models\Tenant\Loan;
use App\Support\TenantAbsoluteUrl;
use App\Support\WebPushNotification;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class NewLoanApplicationNotification extends Notification
{
    public function __construct(
        public Loan $loan,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (WebPushNotification::enabled()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $this->loan->loadMissing('member');

        $reviewUrl = $this->reviewUrl();

        return (new WebPushMessage)
            ->title(__('New loan application'))
            ->body(__(':name applied for :amount.', [
                'name' => $this->loan->member?->name ?? __('Member'),
                'amount' => number_format((float) $this->loan->amount_requested, 2),
            ]))
            ->icon('/icons/icon-192x192.png')
            ->badge('/icons/icon-192x192.png')
            ->tag('loan-application-' . $this->loan->getKey())
            ->data(['url' => $reviewUrl])
            ->action(__('Review application'), 'review', '/icons/icon-192x192.png')
            ->options(['TTL' => 86400]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $this->loan->loadMissing('member');

        return FilamentNotification::make()
            ->title(__('New loan application'))
            ->body(__(':name applied for :amount.', [
                'name' => $this->loan->member?->name ?? __('Member'),
                'amount' => number_format((float) $this->loan->amount_requested, 2),
            ]))
            ->icon('heroicon-o-document-plus')
            ->iconColor('warning')
            ->actions([
                Action::make('review')
                    ->label(__('Review application'))
                    ->url($this->reviewUrl())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    protected function reviewUrl(): string
    {
        $url = LoanResource::getUrl('edit', ['record' => $this->loan], panel: 'tenant');

        return TenantAbsoluteUrl::resolve($url);
    }
}
