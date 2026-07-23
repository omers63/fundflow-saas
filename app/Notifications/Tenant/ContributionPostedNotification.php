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
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->templatedArrayPayload($notifiable);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $payload = $this->templatedArrayPayload($notifiable);

        return FilamentNotification::make()
            ->title((string) ($payload['title'] ?? __('Contribution posted')))
            ->body((string) ($payload['body'] ?? ''))
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

    /**
     * @return array<string, mixed>
     */
    protected function contentPayload(object $notifiable): array
    {
        return [
            'contribution_id' => $this->contribution->id,
            'url' => $this->contributionsUrl(),
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function templateVariables(object $notifiable): array
    {
        $this->contribution->loadMissing('member');

        $periodLabel = $this->contribution->period?->translatedFormat('F Y') ?? __('Unknown period');
        $currency = Setting::get('general', 'currency', 'USD');
        $amount = MoneyDisplay::format((float) $this->contribution->amount, $currency);

        return [
            'member_name' => (string) ($this->contribution->member?->name ?? ''),
            'amount' => $amount,
            'period' => $periodLabel,
            'action_url' => $this->contributionsUrl(),
            'action_label' => __('View my contributions'),
        ];
    }

    protected function contributionsUrl(): string
    {
        $url = MyContributionResource::getUrl('index', panel: 'member');

        return TenantAbsoluteUrl::resolve($url);
    }
}
