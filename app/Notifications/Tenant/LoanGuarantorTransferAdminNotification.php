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
        return $this->buildTemplatedAdminWebPush(
            $notifiable,
            $this->reviewUrl(),
            'loan-guarantor-transfer-'.$this->loan->getKey(),
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
                ->title($copy['title'] !== '' ? $copy['title'] : __('Loan transferred to guarantor'))
                ->body($copy['body'] !== '' ? $copy['body'] : $this->fallbackBody())
                ->icon('heroicon-o-arrow-path')
                ->iconColor('danger')
                ->actions([
                    Action::make('view')
                        ->label(__('View loan'))
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
        return [
            'loan_id' => (string) $this->loan->id,
            'borrower_name' => (string) $this->borrower->name,
            'guarantor_name' => (string) $this->guarantor->name,
            'action_url' => $this->reviewUrl(),
            'action_label' => __('View loan'),
        ];
    }

    protected function fallbackBody(): string
    {
        return __('Loan #:id moved from :borrower to guarantor :guarantor.', [
            'id' => $this->loan->id,
            'borrower' => $this->borrower->name,
            'guarantor' => $this->guarantor->name,
        ]);
    }

    protected function reviewUrl(): string
    {
        $url = LoanResource::getUrl('view', ['record' => $this->loan], panel: 'tenant');

        return TenantAbsoluteUrl::resolve($url);
    }
}
