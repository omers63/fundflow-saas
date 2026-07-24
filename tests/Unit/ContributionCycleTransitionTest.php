<?php

declare(strict_types=1);

use App\Models\Tenant\Setting;
use App\Services\ContributionCycleService;
use App\Support\ScheduledJobRegistry;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Setting::set('contribution', 'cycle_start_day', '6');
    Carbon::setTestNow(Carbon::parse('2026-07-06 00:35:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('treats the configured cycle start day as the transition day', function () {
    $cycles = app(ContributionCycleService::class);

    expect($cycles->isCycleTransitionDay())->toBeTrue()
        ->and($cycles->isCycleTransitionDay(Carbon::parse('2026-07-05')))->toBeFalse()
        ->and($cycles->isCycleTransitionDay(Carbon::parse('2026-07-07')))->toBeFalse();
});

it('identifies the previous labelled month as the period closed on transition', function () {
    $cycles = app(ContributionCycleService::class);

    expect($cycles->periodClosedByTransition())->toBe([6, 2026])
        ->and($cycles->currentOpenPeriod())->toBe([7, 2026]);
});

it('respects a different cycle start day setting', function () {
    Setting::set('contribution', 'cycle_start_day', '10');
    Carbon::setTestNow(Carbon::parse('2026-07-10 00:30:00'));

    $cycles = app(ContributionCycleService::class);

    expect($cycles->isCycleTransitionDay())->toBeTrue()
        ->and($cycles->periodClosedByTransition())->toBe([6, 2026]);
});

it('schedules close then init every minute so tenant time slots can apply', function () {
    $events = collect(app(Schedule::class)->events());

    $close = $events->first(
        fn ($event): bool => str_contains((string) $event->command, 'contributions:close-window'),
    );
    $init = $events->first(
        fn ($event): bool => str_contains((string) $event->command, 'contributions:init-cycle'),
    );

    expect($close)->not->toBeNull()
        ->and($init)->not->toBeNull()
        ->and($close->expression)->toBe('* * * * *')
        ->and($init->expression)->toBe('* * * * *');
});

it('describes close and init schedules using the tenant cycle start day', function () {
    Setting::set('contribution', 'cycle_start_day', '10');

    $jobs = collect(ScheduledJobRegistry::all())->keyBy('key');

    expect($jobs['contributions:close-window']['schedule'])->toContain('10')
        ->and($jobs['contributions:init-cycle']['schedule'])->toContain('10')
        ->and($jobs['contributions:init-cycle']['schedule'])->toContain('00:35');
});
