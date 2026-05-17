<?php

declare(strict_types=1);

namespace App\Notifications\Tenant;

use App\Models\Tenant\MonthlyStatement;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class MonthlyStatementNotification extends Notification
{
    use Queueable;

    public function __construct(
        public MonthlyStatement $statement,
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
            'title' => __('Monthly statement ready'),
            'body' => __('Your statement for :period is available to download.', [
                'period' => $this->statement->period_formatted,
            ]),
        ];
    }
}
