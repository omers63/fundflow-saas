<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\Transaction;
use App\Notifications\Concerns\DeliversToMemberChannels;
use Illuminate\Notifications\Notification;

class AccountTransactionReversedNotification extends Notification
{
    use DeliversToMemberChannels;

    public function __construct(
        public readonly Transaction $transaction,
        public readonly string $reason,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('Ledger entry reversed'),
            'body' => __('A ledger entry on your account was reversed: :reason', [
                'reason' => $this->reason,
            ]),
            'icon' => 'heroicon-o-arrow-uturn-left',
            'color' => 'warning',
        ];
    }
}
