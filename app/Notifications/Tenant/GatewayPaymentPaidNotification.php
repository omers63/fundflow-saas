<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Member\Pages\CashAccountPage;
use App\Models\Tenant\GatewayPayment;
use App\Notifications\Concerns\DeliversToMemberChannels;
use App\Support\TenantAbsoluteUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class GatewayPaymentPaidNotification extends Notification
{
    use DeliversToMemberChannels;

    public function __construct(public GatewayPayment $payment) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('Payment completed'),
            'body' => __('Gateway payment of :amount was credited to your cash account.', [
                'amount' => number_format((float) $this->payment->amount, 2),
            ]),
            'gateway_payment_id' => $this->payment->id,
            'url' => $this->cashAccountUrl(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $payload = $this->toArray($notifiable);

        return FilamentNotification::make()
            ->title((string) $payload['title'])
            ->body((string) $payload['body'])
            ->icon('heroicon-o-credit-card')
            ->iconColor('success')
            ->actions([
                Action::make('view')
                    ->label(__('Cash account'))
                    ->url($this->cashAccountUrl())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    protected function cashAccountUrl(): string
    {
        return TenantAbsoluteUrl::resolve(CashAccountPage::getUrl(panel: 'member'));
    }
}
