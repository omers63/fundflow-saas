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
Schedule::command('fund:reconcile --monthly')->monthlyOn(2, '06:30');
Schedule::command('contributions:init-cycle')->monthlyOn(1, '08:00');
Schedule::command('contributions:notify')->monthlyOn(1, '09:00');
Schedule::command('contributions:apply')->monthlyOn(5, '09:00');
Schedule::command('contributions:close-window')->monthlyOn(6, '00:30');
Schedule::command('loans:close-emi-window')->monthlyOn(6, '00:45');
Schedule::command('contributions:apply-late-fees')->dailyAt('07:15');
Schedule::command('bank:auto-match')->dailyAt('08:00');
Schedule::command('statements:generate --notify')->monthlyOn(3, '08:00');
Schedule::command('loans:send-due-notifications')->monthlyOn(1, '08:00');
Schedule::command('loans:apply-repayments')->monthlyOn(6, '06:00');
Schedule::command('loans:check-defaults')->dailyAt('07:00');
Schedule::command('delinquency:send-digest')->dailyAt('07:30');
Schedule::command('announcements:dispatch-scheduled')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
Schedule::command('queue:ensure-worker')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
