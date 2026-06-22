<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Member\Resources\MyContributions\MyContributionResource;
use App\Notifications\Concerns\DeliversToMemberChannels;
use App\Support\TenantAbsoluteUrl;
use Carbon\Carbon;
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
        public Carbon $deadline,
        public float $cashBalance,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('Contribution due'),
            'body' => $this->bodyMessage(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title(__('Contribution due'))
            ->body($this->bodyMessage())
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

    protected function bodyMessage(): string
    {
        return __(':amount due for :period by :deadline. Cash balance: :balance.', [
            'amount' => number_format($this->amount, 2),
            'period' => Carbon::create($this->year, $this->month, 1)->translatedFormat('F Y'),
            'deadline' => $this->deadline->translatedFormat('j M Y'),
            'balance' => number_format($this->cashBalance, 2),
        ]);
    }

    protected function contributionsUrl(): string
    {
        $url = MyContributionResource::getUrl('index', panel: 'member');

        return TenantAbsoluteUrl::resolve($url);
    }
}
