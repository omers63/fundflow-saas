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
        return $this->buildTemplatedAdminWebPush(
            $notifiable,
            $this->reviewUrl(),
            'loan-eligibility-override-'.$this->request->getKey(),
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
                ->title($copy['title'] !== '' ? $copy['title'] : __('Loan eligibility review requested'))
                ->body($copy['body'] !== '' ? $copy['body'] : $this->fallbackBody())
                ->icon('heroicon-o-shield-exclamation')
                ->iconColor('warning')
                ->actions([
                    Action::make('review')
                        ->label(__('Review request'))
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
        $this->request->loadMissing('member');
        $gateCount = count($this->request->failed_gates ?? []);
        $gateLabels = LoanEligibilityGate::labels();
        $firstGate = array_key_first($this->request->failed_gates ?? []);
        $firstLabel = $firstGate !== null ? ($gateLabels[$firstGate] ?? $firstGate) : __('Eligibility');

        return [
            'member_name' => (string) ($this->request->member?->name ?? __('Member')),
            'gate_count' => (string) $gateCount,
            'first_gate' => (string) $firstLabel,
            'action_url' => $this->reviewUrl(),
            'action_label' => __('Review request'),
        ];
    }

    protected function fallbackBody(): string
    {
        $this->request->loadMissing('member');
        $gateCount = count($this->request->failed_gates ?? []);
        $gateLabels = LoanEligibilityGate::labels();
        $firstGate = array_key_first($this->request->failed_gates ?? []);
        $firstLabel = $firstGate !== null ? ($gateLabels[$firstGate] ?? $firstGate) : __('Eligibility');

        return __(':name requested an eligibility review (:count blocked rule(s), first: :gate).', [
            'name' => $this->request->member->name,
            'count' => $gateCount,
            'gate' => $firstLabel,
        ]);
    }

    protected function reviewUrl(): string
    {
        $url = LoanEligibilityOverrideRequestResource::indexUrlForRequest($this->request);

        return TenantAbsoluteUrl::resolve($url);
    }
}
