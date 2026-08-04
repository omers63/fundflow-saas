<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Tenant automation commands must wake every minute. Each command gates itself via
// Automation → Schedule settings (exact HH:MM times, cycle days, polling intervals).
// Registering them as hourly()/daily() only invoked them at :00 / midnight — so
// defaults like daily reconcile 06:20 and nightly 06:30 never matched a wake.
// queue:ensure-worker is a host-level watchdog, not tenant-scoped.
Schedule::command('fund:assert-master-invariants')->everyMinute()->withoutOverlapping();
Schedule::command('fund:reconcile --daily')->everyMinute()->withoutOverlapping();
Schedule::command('fund:nightly-reconciliation')->everyMinute()->withoutOverlapping();
Schedule::command('fund:reconcile --monthly')->everyMinute()->withoutOverlapping();
Schedule::command('statements:generate --notify')->everyMinute()->withoutOverlapping();
Schedule::command('contributions:close-window')->everyMinute()->withoutOverlapping();
Schedule::command('contributions:init-cycle')->everyMinute()->withoutOverlapping();
Schedule::command('contributions:notify')->everyMinute()->withoutOverlapping();
Schedule::command('loans:send-due-notifications')->everyMinute()->withoutOverlapping();
Schedule::command('contributions:apply')->everyMinute()->withoutOverlapping();
Schedule::command('loans:apply-repayments')->everyMinute()->withoutOverlapping();
Schedule::command('contributions:apply-late-fees')->everyMinute()->withoutOverlapping();
Schedule::command('loans:check-defaults')->everyMinute()->withoutOverlapping();
Schedule::command('loans:close-emi-window')->everyMinute()->withoutOverlapping();
Schedule::command('bank:auto-match')->everyMinute()->withoutOverlapping();
Schedule::command('delinquency:send-digest')->everyMinute()->withoutOverlapping();
Schedule::command('fund:send-status-digest')->everyMinute()->withoutOverlapping();
Schedule::command('announcements:dispatch-scheduled')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
Schedule::command('members:send-onboarding-greeting')->everyMinute()->withoutOverlapping();
// Host-level queue watchdog only — never schedule when Supervisor owns queue:work.
// Calling queue:restart every minute would bounce the supervisor worker on a failed pgrep.
Schedule::command('queue:ensure-worker')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground()
    ->when(fn (): bool => (bool) config('queue.worker_watchdog.enabled'));
