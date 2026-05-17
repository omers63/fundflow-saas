<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ContributionCycleService;
use App\Services\Loans\LoanRepaymentService;
use Illuminate\Console\Command;

class LoansSendDueNotificationsCommand extends Command
{
    protected $signature = 'loans:send-due-notifications {--month=} {--year=}';

    protected $description = 'Notify active borrowers of installments due in the given or current open period';

    public function handle(LoanRepaymentService $repayments, ContributionCycleService $cycle): int
    {
        if ($this->option('month') && $this->option('year')) {
            $month = (int) $this->option('month');
            $year = (int) $this->option('year');
        } else {
            [$month, $year] = $cycle->currentOpenPeriod();
        }

        $count = $repayments->sendDueNotifications($month, $year);

        $this->info(__('Sent :count due notification(s) for :period.', [
            'count' => $count,
            'period' => $cycle->periodLabel($month, $year),
        ]));

        return self::SUCCESS;
    }
}
