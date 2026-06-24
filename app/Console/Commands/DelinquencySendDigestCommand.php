<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\Loans\DelinquencyDigestService;
use Filament\Facades\Filament;
use Illuminate\Console\Command;

class DelinquencySendDigestCommand extends Command
{
    use TenantAwareScheduledCommand;

    protected $signature = 'delinquency:send-digest';

    protected $description = 'Send delinquency summary notifications to tenant administrators';

    public function handle(DelinquencyDigestService $digest): int
    {
        Filament::setCurrentPanel('tenant');

        $count = $digest->notifyAdminsIfNeeded();

        $this->info(__('Notified :count administrator(s).', ['count' => $count]));

        return self::SUCCESS;
    }
}
