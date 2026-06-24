<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\Tenant\MemberAnnouncementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class DispatchScheduledMemberAnnouncementsCommand extends Command
{
    use TenantAwareScheduledCommand;

    protected $signature = 'announcements:dispatch-scheduled';

    protected $description = 'Send member announcements whose scheduled time has passed';

    public function handle(MemberAnnouncementService $announcements): int
    {
        if (! Schema::hasTable('member_announcements')) {
            return self::SUCCESS;
        }

        $count = $announcements->dispatchDueScheduled();

        if ($count > 0) {
            $this->info(__('Dispatched :count scheduled announcement(s).', ['count' => $count]));
        }

        return self::SUCCESS;
    }
}
