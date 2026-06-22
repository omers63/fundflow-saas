<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Member\Pages\ApplyForLoan;
use App\Models\Tenant\LoanEligibilityOverrideRequest;
use App\Notifications\Concerns\DeliversToMemberChannels;
use App\Support\TenantAbsoluteUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LoanEligibilityOverrideApprovedNotification extends Notification
{
    use DeliversToMemberChannels;
    use Queueable;

    public function __construct(
        public LoanEligibilityOverrideRequest $request,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('Loan eligibility approved'),
            'body' => $this->bodyMessage(),
            'loan_eligibility_override_request_id' => $this->request->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title(__('Loan eligibility approved'))
            ->body($this->bodyMessage())
            ->icon('heroicon-o-check-circle')
            ->iconColor('success')
            ->actions([
                Action::make('apply')
                    ->label(__('Apply for loan'))
                    ->url($this->applyUrl())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    protected function bodyMessage(): string
    {
        if (filled($this->request->admin_remarks)) {
            return __('Your eligibility review was approved. :note', [
                'note' => $this->request->admin_remarks,
            ]);
        }

        return __('Your eligibility review was approved. You may apply for a loan when ready.');
    }

    protected function applyUrl(): string
    {
        $url = ApplyForLoan::getUrl(panel: 'member');

        return TenantAbsoluteUrl::resolve($url);
    }
}
