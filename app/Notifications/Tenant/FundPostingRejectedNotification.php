<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Filament\Member\Resources\MyFundPostings\MyFundPostingResource;
use App\Models\Tenant\FundPosting;
use App\Notifications\Concerns\DeliversToMemberChannels;
use App\Notifications\Tenant\Concerns\BuildsFundPostingDatabaseMessage;
use App\Support\Notifications\FundPostingNotificationFormatter;
use App\Support\TenantAbsoluteUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class FundPostingRejectedNotification extends Notification
{
    use BuildsFundPostingDatabaseMessage;
    use DeliversToMemberChannels;

    public function __construct(
        public FundPosting $fundPosting,
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
            ->title((string) ($payload['title'] ?? __('Deposit rejected')))
            ->body((string) ($payload['body'] ?? $this->fundPostingDatabaseBody($this->fundPosting, null, 'rejected')))
            ->icon('heroicon-o-x-circle')
            ->iconColor('danger')
            ->actions([
                Action::make('view')
                    ->label(__('View my deposits'))
                    ->url($this->memberDepositsUrl())
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
            'fund_posting_id' => $this->fundPosting->id,
            'status' => 'rejected',
            'url' => $this->memberDepositsUrl(),
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function templateVariables(object $notifiable): array
    {
        $lines = FundPostingNotificationFormatter::depositDetailRows($this->fundPosting);

        if (filled($this->fundPosting->admin_remarks)) {
            $lines[] = [
                'label' => __('Reason'),
                'value' => (string) $this->fundPosting->admin_remarks,
            ];
        }

        return [
            'member_name' => (string) ($this->fundPosting->member?->name ?? ''),
            'amount' => number_format((float) $this->fundPosting->amount, 2),
            'body' => FundPostingNotificationFormatter::plainTextFromRows($lines),
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
