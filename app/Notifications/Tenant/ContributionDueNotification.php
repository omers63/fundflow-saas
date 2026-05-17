<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ContributionDueNotification extends Notification
{
    use Queueable;

    public function __construct(
        public int $month,
        public int $year,
        public float $amount,
        public Carbon $deadline,
        public float $cashBalance,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => __('Contribution due'),
            'body' => __(':amount due for :period by :deadline. Cash balance: :balance.', [
                'amount' => number_format($this->amount, 2),
                'period' => Carbon::create($this->year, $this->month, 1)->translatedFormat('F Y'),
                'deadline' => $this->deadline->translatedFormat('j M Y'),
                'balance' => number_format($this->cashBalance, 2),
            ]),
        ];
    }
}
