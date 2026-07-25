<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Notifications\Concerns\DeliversToAdminChannels;
use App\Services\Loans\LoanTransferPreview;
use App\Support\TenantAbsoluteUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class LoanAdminTransferAdminNotification extends Notification
{
    use DeliversToAdminChannels;

    public function __construct(
        public readonly Loan $loan,
        public readonly Member $borrower,
        public readonly Member $recipient,
        public readonly string $mode,
        public readonly ?Loan $sourceLoan = null,
    ) {}

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->buildTemplatedAdminWebPush(
            $notifiable,
            $this->reviewUrl(),
            'loan-admin-transfer-'.$this->loan->getKey(),
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
                ->title($copy['title'] !== '' ? $copy['title'] : __('Admin loan transfer'))
                ->body($copy['body'] !== '' ? $copy['body'] : $this->fallbackBody())
                ->icon('heroicon-o-arrows-right-left')
                ->iconColor('warning')
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
            'source_loan_id' => (string) ($this->sourceLoan?->id ?? $this->loan->id),
            'borrower_name' => (string) $this->borrower->name,
            'recipient_name' => (string) $this->recipient->name,
            'mode' => $this->mode === LoanTransferPreview::MODE_FULL
                ? __('full')
                : __('remaining'),
            'action_url' => $this->reviewUrl(),
            'action_label' => __('View loan'),
        ];
    }

    protected function fallbackBody(): string
    {
        return __('Loan #:id transferred from :borrower to :recipient (:mode).', [
            'id' => $this->sourceLoan?->id ?? $this->loan->id,
            'borrower' => $this->borrower->name,
            'recipient' => $this->recipient->name,
            'mode' => $this->mode === LoanTransferPreview::MODE_FULL
                ? __('full')
                : __('remaining'),
        ]);
    }

    protected function reviewUrl(): string
    {
        $url = LoanResource::getUrl('view', ['record' => $this->loan], panel: 'tenant');

        return TenantAbsoluteUrl::resolve($url);
    }
}
