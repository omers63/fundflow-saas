<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('contributions:notify')->monthlyOn(1, '09:00');
Schedule::command('contributions:apply')->monthlyOn(5, '09:00');
Schedule::command('statements:generate --notify')->monthlyOn(3, '08:00');
Schedule::command('loans:send-due-notifications')->monthlyOn(1, '08:00');
Schedule::command('loans:apply-repayments')->monthlyOn(6, '06:00');
Schedule::command('loans:check-defaults')->dailyAt('07:00');
Schedule::command('delinquency:send-digest')->dailyAt('07:30');
