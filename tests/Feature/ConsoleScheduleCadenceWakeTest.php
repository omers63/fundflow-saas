<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/**
 * Cadence-gated tenant jobs compare wall-clock H:i to Automation Schedule times
 * (e.g. daily reconcile 06:20). Laravel must therefore wake them every minute —
 * not only hourly (:00) or daily (00:00).
 */
it('registers cadence-gated automation commands every minute', function () {
    $events = collect(Schedule::events());

    $mustWakeEveryMinute = [
        'fund:assert-master-invariants',
        'fund:reconcile --daily',
        'fund:nightly-reconciliation',
        'fund:reconcile --monthly',
        'statements:generate --notify',
        'contributions:close-window',
        'contributions:init-cycle',
        'contributions:notify',
        'loans:send-due-notifications',
        'contributions:apply',
        'loans:apply-repayments',
        'contributions:apply-late-fees',
        'loans:check-defaults',
        'loans:close-emi-window',
        'bank:auto-match',
        'delinquency:send-digest',
        'fund:send-status-digest',
        'announcements:dispatch-scheduled',
        'members:send-onboarding-greeting',
    ];

    foreach ($mustWakeEveryMinute as $command) {
        $match = $events->first(
            fn ($event) => is_string($event->command ?? null)
                && str_contains($event->command, $command)
        );

        expect($match)->not->toBeNull("Expected scheduled command containing [{$command}]");
        expect($match->expression)->toBe('* * * * *', "[{$command}] must wake every minute");
    }
});

it('keeps the queue worker watchdog on an hourly wake', function () {
    $event = collect(Schedule::events())->first(
        fn ($event) => is_string($event->command ?? null)
            && str_contains($event->command, 'queue:ensure-worker')
    );

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('0 * * * *');
});
