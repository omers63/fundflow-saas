<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\ContributionCycleService;
use App\Support\AutomationScheduleSettings;
use Illuminate\Console\Command;

class SendContributionNotifications extends Command
{
    use TenantAwareScheduledCommand;

    protected $signature = 'contributions:notify {--month=} {--year=} {--force : Run even when not in a configured notify slot}';

    protected $description = 'Send contribution due notifications for a cycle period';

    public function handle(ContributionCycleService $cycles): int
    {
        $forcedPeriod = $this->option('month') && $this->option('year');

        if (! $this->option('force') && ! $forcedPeriod && ! AutomationScheduleSettings::isContributionDueNotifySlot()) {
            $this->skipScheduledRunRecording = true;
            $this->info(__('Skipped: not a configured contribution due-notification slot (days :days at :time).', [
                'days' => implode(', ', AutomationScheduleSettings::contributionDueNotifyDays()),
                'time' => AutomationScheduleSettings::contributionDueNotifyTime(),
            ]));

            return self::SUCCESS;
        }

        if ($forcedPeriod) {
            $month = (int) $this->option('month');
            $year = (int) $this->option('year');
        } else {
            [$month, $year] = $cycles->currentOpenPeriod();
        }

        $stats = $cycles->sendDueNotifications($month, $year);

        $this->info(__('Notified :count member(s) for :period.', [
            'count' => $stats['notified'],
            'period' => $cycles->periodLabel($month, $year),
        ]));

        return self::SUCCESS;
    }
}
