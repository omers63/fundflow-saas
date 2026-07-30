<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Tenant automation commands wake every minute; each gates itself via Automation →
// Schedule settings (daily/weekly/monthly cadence, cycle days, polling intervals).
// queue:ensure-worker is a host-level watchdog, not tenant-scoped.
Schedule::command('fund:assert-master-invariants')->hourly()->withoutOverlapping();
Schedule::command('fund:reconcile --daily')->hourly()->withoutOverlapping();
Schedule::command('fund:nightly-reconciliation')->hourly()->withoutOverlapping();
Schedule::command('fund:reconcile --monthly')->hourly()->withoutOverlapping();
Schedule::command('statements:generate --notify')->hourly()->withoutOverlapping();
Schedule::command('contributions:close-window')->hourly()->withoutOverlapping();
Schedule::command('contributions:init-cycle')->hourly()->withoutOverlapping();
Schedule::command('contributions:notify')->hourly()->withoutOverlapping();
Schedule::command('loans:send-due-notifications')->hourly()->withoutOverlapping();
Schedule::command('contributions:apply')->hourly()->withoutOverlapping();
Schedule::command('loans:apply-repayments')->hourly()->withoutOverlapping();
Schedule::command('contributions:apply-late-fees')->hourly()->withoutOverlapping();
Schedule::command('loans:check-defaults')->hourly()->withoutOverlapping();
Schedule::command('loans:close-emi-window')->hourly()->withoutOverlapping();
Schedule::command('bank:auto-match')->hourly()->withoutOverlapping();
Schedule::command('delinquency:send-digest')->hourly()->withoutOverlapping();
Schedule::command('fund:send-status-digest')->daily()->withoutOverlapping();
Schedule::command('announcements:dispatch-scheduled')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
Schedule::command('members:send-onboarding-greeting')->daily()->withoutOverlapping();
// Host-level queue watchdog only — never schedule when Supervisor owns queue:work.
// Calling queue:restart every minute would bounce the supervisor worker on a failed pgrep.
Schedule::command('queue:ensure-worker')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground()
    ->when(fn (): bool => (bool) config('queue.worker_watchdog.enabled'));
