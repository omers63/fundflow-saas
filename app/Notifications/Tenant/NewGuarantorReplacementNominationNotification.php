<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Support\AdminNotificationActions;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Models\Tenant\LoanGuarantorReplacementRequest;
use App\Notifications\Concerns\DeliversToAdminChannels;
use App\Support\TenantAbsoluteUrl;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class NewGuarantorReplacementNominationNotification extends Notification
{
    use DeliversToAdminChannels;

    public function __construct(
        public LoanGuarantorReplacementRequest $replacementRequest,
    ) {}

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->buildTemplatedAdminWebPush(
            $notifiable,
            $this->reviewUrl(),
            'guarantor-replacement-'.$this->replacementRequest->getKey(),
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
                ->title($copy['title'] !== '' ? $copy['title'] : __('Guarantor replacement nomination'))
                ->body($copy['body'] !== '' ? $copy['body'] : $this->fallbackBody())
                ->icon('heroicon-o-user-plus')
                ->iconColor('warning')
                ->actions([
                    AdminNotificationActions::review(
                        __('Review loan'),
                        $this->reviewUrl(),
                    ),
                ])
                ->getDatabaseMessage();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function adminTemplateVariables(object $notifiable): array
    {
        $this->replacementRequest->loadMissing(['borrower', 'loan']);

        return [
            'member_name' => (string) ($this->replacementRequest->borrower->name ?? __('Member')),
            'proposed_name' => (string) ($this->replacementRequest->proposed_guarantor_name ?? ''),
            'loan_id' => (string) $this->replacementRequest->loan_id,
            'action_url' => $this->reviewUrl(),
            'action_label' => __('Review'),
        ];
    }

    protected function fallbackBody(): string
    {
        $this->replacementRequest->loadMissing('borrower');

        return __(':borrower nominated :name as guarantor for loan #:id.', [
            'borrower' => $this->replacementRequest->borrower->name ?? __('Member'),
            'name' => $this->replacementRequest->proposed_guarantor_name ?? __('a member'),
            'id' => $this->replacementRequest->loan_id,
        ]);
    }

    protected function reviewUrl(): string
    {
        $this->replacementRequest->loadMissing('loan');

        return TenantAbsoluteUrl::resolve(
            LoanResource::getUrl('view', ['record' => $this->replacementRequest->loan_id], panel: 'tenant'),
        );
    }
}
