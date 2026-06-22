<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Member\Resources\MyContributions\MyContributionResource;
use App\Filament\Support\MoneyDisplay;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Setting;
use App\Notifications\Concerns\DeliversToMemberChannels;
use App\Support\TenantAbsoluteUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ContributionPostedNotification extends Notification
{
    use DeliversToMemberChannels;
    use Queueable;

    public function __construct(
        public Contribution $contribution,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('Contribution posted'),
            'body' => $this->bodyMessage(),
            'contribution_id' => $this->contribution->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title(__('Contribution posted'))
            ->body($this->bodyMessage())
            ->icon('heroicon-o-check-circle')
            ->iconColor('success')
            ->actions([
                Action::make('view')
                    ->label(__('View my contributions'))
                    ->url($this->contributionsUrl())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    protected function bodyMessage(): string
    {
        $this->contribution->loadMissing('member');

        $periodLabel = $this->contribution->period?->translatedFormat('F Y') ?? __('Unknown period');
        $currency = Setting::get('general', 'currency', 'USD');
        $amount = MoneyDisplay::format((float) $this->contribution->amount, $currency);
        $lateFee = (float) ($this->contribution->late_fee_amount ?? 0);

        if ($lateFee > 0.00001) {
            return __(':amount for :period posted (including :fee late fee).', [
                'amount' => $amount,
                'period' => $periodLabel,
                'fee' => MoneyDisplay::format($lateFee, $currency),
            ]);
        }

        return __(':amount for :period has been posted to your fund account.', [
            'amount' => $amount,
            'period' => $periodLabel,
        ]);
    }

    protected function contributionsUrl(): string
    {
        $url = MyContributionResource::getUrl('index', panel: 'member');

        return TenantAbsoluteUrl::resolve($url);
    }
}
