<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Notifications\Concerns\DeliversToAdminChannels;
use App\Support\TenantAbsoluteUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class LoanGuarantorTransferAdminNotification extends Notification
{
    use DeliversToAdminChannels;

    public function __construct(
        public readonly Loan $loan,
        public readonly Member $borrower,
        public readonly Member $guarantor,
    ) {}

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->buildAdminWebPushFor(
            $notifiable,
            __('Loan transferred to guarantor'),
            __('Loan #:id moved from :borrower to guarantor :guarantor.', [
                'id' => $this->loan->id,
                'borrower' => $this->borrower->name,
                'guarantor' => $this->guarantor->name,
            ]),
            $this->reviewUrl(),
            'loan-guarantor-transfer-'.$this->loan->getKey(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title(__('Loan transferred to guarantor'))
            ->body(__('Loan #:id moved from :borrower to guarantor :guarantor.', [
                'id' => $this->loan->id,
                'borrower' => $this->borrower->name,
                'guarantor' => $this->guarantor->name,
            ]))
            ->icon('heroicon-o-arrow-path')
            ->iconColor('danger')
            ->actions([
                Action::make('view')
                    ->label(__('View loan'))
                    ->url($this->reviewUrl())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    protected function reviewUrl(): string
    {
        $url = LoanResource::getUrl('view', ['record' => $this->loan], panel: 'tenant');

        return TenantAbsoluteUrl::resolve($url);
    }
}
