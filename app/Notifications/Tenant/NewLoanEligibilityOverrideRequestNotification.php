<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Tenant\Resources\LoanEligibilityOverrideRequests\LoanEligibilityOverrideRequestResource;
use App\Models\Tenant\LoanEligibilityOverrideRequest;
use App\Notifications\Concerns\DeliversToAdminChannels;
use App\Support\LoanEligibilityGate;
use App\Support\TenantAbsoluteUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class NewLoanEligibilityOverrideRequestNotification extends Notification
{
    use DeliversToAdminChannels;

    public function __construct(
        public LoanEligibilityOverrideRequest $request,
    ) {}

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $this->request->loadMissing('member');

        return $this->buildAdminWebPushFor(
            $notifiable,
            __('Loan eligibility review requested'),
            __(':name requested an eligibility review.', [
                'name' => $this->request->member?->name ?? __('Member'),
            ]),
            $this->reviewUrl(),
            'loan-eligibility-override-'.$this->request->getKey(),
        );
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

        return TenantAbsoluteUrl::resolve($url);
    }
}
