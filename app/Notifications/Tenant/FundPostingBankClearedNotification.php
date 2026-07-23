<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Member\Resources\MyFundPostings\MyFundPostingResource;
use App\Models\Tenant\FundPosting;
use App\Notifications\Concerns\DeliversToMemberChannels;
use App\Support\TenantAbsoluteUrl;
use Illuminate\Notifications\Notification;

class FundPostingBankClearedNotification extends Notification
{
    use DeliversToMemberChannels;

    public function __construct(
        public readonly FundPosting $fundPosting,
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
    protected function contentPayload(object $notifiable): array
    {
        return [
            'fund_posting_id' => $this->fundPosting->id,
            'url' => $this->memberDepositsUrl(),
            'icon' => 'heroicon-o-check-badge',
            'color' => 'success',
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function templateVariables(object $notifiable): array
    {
        $body = __('Your deposit of :amount has been matched on the bank statement.', [
            'amount' => number_format((float) $this->fundPosting->amount, 2),
        ]);

        return [
            'member_name' => (string) ($this->fundPosting->member?->name ?? ''),
            'amount' => number_format((float) $this->fundPosting->amount, 2),
            'body' => $body,
            'action_url' => $this->memberDepositsUrl(),
            'action_label' => __('View my deposits'),
        ];
    }

    protected function memberDepositsUrl(): string
    {
        $url = MyFundPostingResource::getUrl('index', panel: 'member');

        return TenantAbsoluteUrl::resolve($url);
    }
}
