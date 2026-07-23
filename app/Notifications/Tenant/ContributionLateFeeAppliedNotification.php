<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\Contribution;
use App\Notifications\Concerns\DeliversToMemberChannels;
use Illuminate\Notifications\Notification;

class ContributionLateFeeAppliedNotification extends Notification
{
    use DeliversToMemberChannels;

    public function __construct(
        public readonly Contribution $contribution,
        public readonly float $lateFeeAmount,
        public readonly int $tier,
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
            'icon' => 'heroicon-o-clock',
            'color' => 'warning',
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function templateVariables(object $notifiable): array
    {
        return [
            'member_name' => (string) ($this->contribution->member?->name ?? ''),
            'amount' => number_format($this->lateFeeAmount, 2),
            'period' => $this->contribution->period?->format('M Y') ?? __('contribution'),
            'action_url' => null,
            'action_label' => __('Open'),
        ];
    }
}
