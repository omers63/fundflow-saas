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
        return [
            'title' => __('Contribution late fee applied'),
            'body' => __('A late fee of :amount was applied to your :period contribution (tier :tier).', [
                'amount' => number_format($this->lateFeeAmount, 2),
                'period' => $this->contribution->period?->format('M Y') ?? __('contribution'),
                'tier' => $this->tier,
            ]),
            'icon' => 'heroicon-o-clock',
            'color' => 'warning',
        ];
    }
}
