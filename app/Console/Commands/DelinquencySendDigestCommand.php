<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\Loans\DelinquencyDigestService;
use App\Support\AutomationScheduleSettings;
use Filament\Facades\Filament;
use Illuminate\Console\Command;

class DelinquencySendDigestCommand extends Command
{
    use TenantAwareScheduledCommand;

    protected $signature = 'delinquency:send-digest
        {--force : Run even when not in the configured daily slot}';

    protected $description = 'Send delinquency summary notifications to tenant administrators';

    public function handle(DelinquencyDigestService $digest): int
    {
        if (! $this->option('force') && ! AutomationScheduleSettings::isDelinquencyDigestSlot()) {
            $this->skipScheduledRunRecording = true;
            $this->info(__('Skipped: delinquency digest runs at :time.', [
                'time' => AutomationScheduleSettings::delinquencyDigestTime(),
            ]));

            return self::SUCCESS;
        }

        Filament::setCurrentPanel('tenant');

        $count = $digest->notifyAdminsIfNeeded();

        $this->info(__('Notified :count administrator(s).', ['count' => $count]));

        return self::SUCCESS;
    }
}
