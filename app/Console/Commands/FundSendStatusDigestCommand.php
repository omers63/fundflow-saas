<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\FundStatusDigestService;
use App\Support\AutomationScheduleSettings;
use Filament\Facades\Filament;
use Illuminate\Console\Command;

final class FundSendStatusDigestCommand extends Command
{
    use TenantAwareScheduledCommand;

    protected $signature = 'fund:send-status-digest
        {--force : Run even when not in the configured daily slot}';

    protected $description = 'Send fund status summary notifications to tenant administrators';

    public function handle(FundStatusDigestService $service): int
    {
        if (! $this->option('force') && ! AutomationScheduleSettings::isFundStatusDigestSlot()) {
            $this->skipScheduledRunRecording = true;
            $this->info(__('Skipped: :schedule.', [
                'schedule' => AutomationScheduleSettings::fundStatusDigestScheduleLabel(),
            ]));

            return self::SUCCESS;
        }

        Filament::setCurrentPanel('tenant');

        $count = $service->notifyAdminsIfNeeded();
        $this->info(__('Notified :count administrator(s).', ['count' => $count]));

        return self::SUCCESS;
    }
}
