<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Models\Tenant\Loan;
use App\Notifications\Concerns\DeliversToAdminChannels;
use App\Support\TenantAbsoluteUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class NewLoanApplicationNotification extends Notification
{
    use DeliversToAdminChannels;

    public function __construct(
        public Loan $loan,
    ) {}

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $this->loan->loadMissing('member');

        return $this->buildAdminWebPushFor(
            $notifiable,
            __('New loan application'),
            __(':name applied for :amount.', [
                'name' => $this->loan->member?->name ?? __('Member'),
                'amount' => number_format((float) $this->loan->amount_requested, 2),
            ]),
            $this->reviewUrl(),
            'loan-application-'.$this->loan->getKey(),
        );
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
