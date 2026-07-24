<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\Tenant\MemberAnnouncementService;
use App\Support\AutomationScheduleSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class DispatchScheduledMemberAnnouncementsCommand extends Command
{
    use TenantAwareScheduledCommand;

    protected $signature = 'announcements:dispatch-scheduled
        {--force : Run even when announcement dispatch is disabled in automation settings}';

    protected $description = 'Send member announcements whose scheduled time has passed';

    public function handle(MemberAnnouncementService $announcements): int
    {
        if (! Schema::hasTable('member_announcements')) {
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! AutomationScheduleSettings::isAnnouncementsDispatchSlot()) {
            $this->skipScheduledRunRecording = true;
            $this->info(__('Skipped: scheduled announcement dispatch is disabled or not in its polling slot (:interval).', [
                'interval' => AutomationScheduleSettings::pollingIntervalOptions()[AutomationScheduleSettings::dispatchAnnouncementsIntervalMinutes()]
                    ?? __('Every :n minutes', ['n' => AutomationScheduleSettings::dispatchAnnouncementsIntervalMinutes()]),
            ]));

            return self::SUCCESS;
        }

        $count = $announcements->dispatchDueScheduled();

        if ($count > 0) {
            $this->info(__('Dispatched :count scheduled announcement(s).', ['count' => $count]));
        }

        return self::SUCCESS;
    }
}
