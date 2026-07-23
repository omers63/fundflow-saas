<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('fund:assert-master-invariants')->dailyAt('06:00');
Schedule::command('fund:reconcile --daily')->dailyAt('06:20');
Schedule::command('fund:nightly-reconciliation')->dailyAt('06:30');
// Month-boundary jobs (day from Settings → month boundary / cycle start day). Commands no-op other days.
Schedule::command('fund:reconcile --monthly')->dailyAt('00:30');
Schedule::command('statements:generate --notify')->dailyAt('00:30');
// Close previous / open next contribution cycle on Settings → cycle start day (commands no-op other days).
Schedule::command('contributions:close-window')->dailyAt('00:30');
Schedule::command('contributions:init-cycle')->dailyAt('00:35');
// Due notifications + applies: every minute; commands match configured days/times per tenant.
Schedule::command('contributions:notify')->everyMinute()->withoutOverlapping();
Schedule::command('loans:send-due-notifications')->everyMinute()->withoutOverlapping();
Schedule::command('contributions:apply')->everyMinute()->withoutOverlapping();
Schedule::command('loans:apply-repayments')->everyMinute()->withoutOverlapping();
// Late fees run immediately after contributions:apply; delinquency after loans:apply-repayments.
Schedule::command('loans:close-emi-window')->monthlyOn(6, '00:45');
Schedule::command('bank:auto-match')->dailyAt('08:00');
Schedule::command('delinquency:send-digest')->dailyAt('07:30');
Schedule::command('announcements:dispatch-scheduled')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
Schedule::command('queue:ensure-worker')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
