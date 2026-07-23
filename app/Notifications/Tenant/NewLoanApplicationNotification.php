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
        return $this->buildTemplatedAdminWebPush(
            $notifiable,
            $this->reviewUrl(),
            'loan-application-'.$this->loan->getKey(),
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
                ->title($copy['title'] !== '' ? $copy['title'] : __('New loan application'))
                ->body($copy['body'] !== '' ? $copy['body'] : $this->fallbackBody())
                ->icon('heroicon-o-document-plus')
                ->iconColor('warning')
                ->actions([
                    Action::make('review')
                        ->label(__('Review application'))
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
        $this->loan->loadMissing('member');

        return [
            'member_name' => (string) ($this->loan->member?->name ?? __('Member')),
            'amount' => number_format((float) $this->loan->amount_requested, 2),
            'action_url' => $this->reviewUrl(),
            'action_label' => __('Review application'),
        ];
    }

    protected function fallbackBody(): string
    {
        $this->loan->loadMissing('member');

        return __(':name applied for :amount.', [
            'name' => $this->loan->member?->name ?? __('Member'),
            'amount' => number_format((float) $this->loan->amount_requested, 2),
        ]);
    }

    protected function reviewUrl(): string
    {
        $url = LoanResource::getUrl('edit', ['record' => $this->loan], panel: 'tenant');

        return TenantAbsoluteUrl::resolve($url);
    }
}
