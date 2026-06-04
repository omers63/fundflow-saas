<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Models\Tenant\LoanEligibilityOverrideRequest;
use App\Notifications\Concerns\DeliversToMemberChannels;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class LoanEligibilityOverrideRejectedNotification extends Notification
{
    use DeliversToMemberChannels;
    use Queueable;

    public function __construct(
        public LoanEligibilityOverrideRequest $request,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('Loan eligibility review declined'),
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
            ->title(__('Loan eligibility review declined'))
            ->body($this->bodyMessage())
            ->icon('heroicon-o-x-circle')
            ->iconColor('danger')
            ->actions([
                Action::make('view')
                    ->label(__('My loans'))
                    ->url($this->loansUrl())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    protected function bodyMessage(): string
    {
        return __('Your eligibility review request was declined. :reason', [
            'reason' => $this->request->admin_remarks ?: __('Contact the fund office if you have questions.'),
        ]);
    }

    protected function loansUrl(): string
    {
        $url = MyLoanResource::getUrl('index', panel: 'member');

        return str_starts_with($url, 'http') ? $url : URL::to($url);
    }
}
