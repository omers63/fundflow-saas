<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanRepaymentService;
use App\Support\AutomationScheduleSettings;
use Illuminate\Console\Command;

class LoansSendDueNotificationsCommand extends Command
{
    use TenantAwareScheduledCommand;

    protected $signature = 'loans:send-due-notifications {--month=} {--year=} {--force : Run even when not in a configured notify slot}';

    protected $description = 'Notify active borrowers of installments due in the given or current open period';

    public function handle(LoanRepaymentService $repayments, ContributionCycleService $cycle): int
    {
        $forcedPeriod = $this->option('month') && $this->option('year');

        if (! $this->option('force') && ! $forcedPeriod && ! AutomationScheduleSettings::isLoanDueNotifySlot()) {
            $this->skipScheduledRunRecording = true;
            $this->info(__('Skipped: not a configured loan due-notification slot (days :days at :time).', [
                'days' => implode(', ', AutomationScheduleSettings::loanDueNotifyDays()),
                'time' => AutomationScheduleSettings::loanDueNotifyTime(),
            ]));

            return self::SUCCESS;
        }

        if ($forcedPeriod) {
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
