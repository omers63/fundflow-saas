<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\ContributionCycleService;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendContributionNotifications extends Command
{
    use TenantAwareScheduledCommand;

    protected $signature = 'contributions:notify {--month=} {--year=}';

    protected $description = 'Send contribution due notifications for a cycle period';

    public function handle(ContributionCycleService $cycles): int
    {
        $month = $this->option('month') ? (int) $this->option('month') : null;
        $year = $this->option('year') ? (int) $this->option('year') : null;

        if ($month === null || $year === null) {
            $previous = BusinessDay::now()->subMonthNoOverflow();
            $month = (int) $previous->month;
            $year = (int) $previous->year;
        }

        $count = $cycles->sendDueNotifications($month, $year);

        $this->info(__('Notified :count member(s) for :period.', [
            'count' => $count,
            'period' => Carbon::create($year, $month, 1)->format('F Y'),
        ]));

        return self::SUCCESS;
    }
}
