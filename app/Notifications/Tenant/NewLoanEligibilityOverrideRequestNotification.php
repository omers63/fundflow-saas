<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Tenant\Resources\LoanEligibilityOverrideRequests\LoanEligibilityOverrideRequestResource;
use App\Models\Tenant\LoanEligibilityOverrideRequest;
use App\Support\LoanEligibilityGate;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class NewLoanEligibilityOverrideRequestNotification extends Notification
{
    use Queueable;

    public function __construct(
        public LoanEligibilityOverrideRequest $request,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $this->request->loadMissing('member');

        $gateCount = count($this->request->failed_gates ?? []);
        $gateLabels = LoanEligibilityGate::labels();
        $firstGate = array_key_first($this->request->failed_gates ?? []);
        $firstLabel = $firstGate !== null ? ($gateLabels[$firstGate] ?? $firstGate) : __('Eligibility');

        return FilamentNotification::make()
            ->title(__('Loan eligibility review requested'))
            ->body(__(':name requested an eligibility review (:count blocked rule(s), first: :gate).', [
                'name' => $this->request->member->name,
                'count' => $gateCount,
                'gate' => $firstLabel,
            ]))
            ->icon('heroicon-o-shield-exclamation')
            ->iconColor('warning')
            ->actions([
                Action::make('review')
                    ->label(__('Review request'))
                    ->url($this->reviewUrl())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    protected function reviewUrl(): string
    {
        $url = LoanEligibilityOverrideRequestResource::indexUrlForRequest($this->request);

        return str_starts_with($url, 'http') ? $url : URL::to($url);
    }
}
