<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\QueueWorkerSupervisor;
use Illuminate\Console\Command;

class EnsureQueueWorkerCommand extends Command
{
    protected $signature = 'queue:ensure-worker';

    protected $description = 'Ensure a queue worker process is running; restart and start one if not';

    public function handle(QueueWorkerSupervisor $supervisor): int
    {
        if (! $supervisor->shouldSupervise()) {
            $this->comment(__('Queue worker watchdog is disabled or not needed for the current queue driver.'));

            return self::SUCCESS;
        }

        $result = $supervisor->ensureWorkerRunning();

        if ($result['started']) {
            $this->info(__('Queue worker was not running. Issued queue:restart and started queue:work in the background.'));

            return self::SUCCESS;
        }

        $this->comment(__('Queue worker is already running.'));

        return self::SUCCESS;
    }
}
