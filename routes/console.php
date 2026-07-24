<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// All tenant automation jobs run every minute; each command matches configured
// day/time slots from Settings → Collection → Automation schedule.
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
Schedule::command('announcements:dispatch-scheduled')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
Schedule::command('members:send-onboarding-greeting')->everyMinute()->withoutOverlapping();
Schedule::command('queue:ensure-worker')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
