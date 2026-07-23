<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Member\Resources\MyContributions\MyContributionResource;
use App\Notifications\Concerns\DeliversToMemberChannels;
use App\Support\TenantAbsoluteUrl;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ContributionDueNotification extends Notification
{
    use DeliversToMemberChannels;
    use Queueable;

    public function __construct(
        public int $month,
        public int $year,
        public float $amount,
        public CarbonInterface $deadline,
        public float $cashBalance,
        public string $memberName = '',
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
            ->title((string) ($payload['title'] ?? __('Contribution due')))
            ->body((string) ($payload['body'] ?? ''))
            ->icon('heroicon-o-banknotes')
            ->iconColor('warning')
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
            'url' => $this->contributionsUrl(),
            'member_name' => $this->memberName,
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function templateVariables(object $notifiable): array
    {
        $deadline = $this->deadline instanceof Carbon
            ? $this->deadline
            : Carbon::parse($this->deadline);

        return [
            'member_name' => $this->memberName,
            'amount' => number_format($this->amount, 2),
            'period' => Carbon::create($this->year, $this->month, 1)->translatedFormat('F Y'),
            'deadline' => $deadline->copy()->startOfDay()->translatedFormat('j M Y'),
            'balance' => number_format($this->cashBalance, 2),
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
