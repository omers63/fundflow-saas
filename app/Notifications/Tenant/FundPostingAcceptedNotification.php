<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Member\Resources\MyFundPostings\MyFundPostingResource;
use App\Models\Tenant\FundPosting;
use App\Notifications\Concerns\DeliversToMemberChannels;
use App\Notifications\Tenant\Concerns\BuildsFundPostingDatabaseMessage;
use App\Services\FundPostingSettlementSummary;
use App\Support\TenantAbsoluteUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class FundPostingAcceptedNotification extends Notification
{
    use BuildsFundPostingDatabaseMessage;
    use DeliversToMemberChannels;

    public function __construct(
        public FundPosting $fundPosting,
        public ?FundPostingSettlementSummary $settlement = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('Deposit accepted'),
            'body' => $this->fundPostingBody($this->fundPosting, $this->settlement),
            'fund_posting_id' => $this->fundPosting->id,
            'status' => 'accepted',
            'settlement' => $this->settlement?->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title(__('Deposit accepted'))
            ->body($this->fundPostingDatabaseBody($this->fundPosting, $this->settlement, 'accepted'))
            ->icon('heroicon-o-check-circle')
            ->iconColor('success')
            ->actions([
                Action::make('view')
                    ->label(__('View my deposits'))
                    ->url($this->memberDepositsUrl())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    protected function memberDepositsUrl(): string
    {
        $url = MyFundPostingResource::getUrl('index', panel: 'member');

        return TenantAbsoluteUrl::resolve($url);
    }
}
