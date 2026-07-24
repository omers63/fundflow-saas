<?php

declare(strict_types=1);

use App\Support\ScheduledJobRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Mail::fake();
    Notification::fake();
});

/**
 * Proves every registry job can enter tenant execution without the central.settings crash.
 * Exit code 1 is allowed for business failures (e.g. master imbalance); crashes are not.
 */
test('all scheduled registry jobs run without bootstrap crash', function () {
    $tenantId = (string) tenant('id');

    foreach (ScheduledJobRegistry::all() as $definition) {
        $command = $definition['command'];
        $parts = preg_split('/\s+/', $command) ?: [];
        $name = array_shift($parts);
        $parameters = [];

        foreach ($parts as $token) {
            if (! str_starts_with($token, '--')) {
                continue;
            }

            $flag = substr($token, 2);

            if (str_contains($flag, '=')) {
                [$key, $value] = explode('=', $flag, 2);
                $parameters['--'.$key] = $value;
            } else {
                $parameters['--'.$flag] = true;
            }
        }

        $definitionOptions = Artisan::all()[$name]?->getDefinition();

        if ($definitionOptions?->hasOption('tenants')) {
            $parameters['--tenants'] = [$tenantId];
        }

        if ($name === 'members:send-onboarding-greeting' && $definitionOptions?->hasOption('member')) {
            $parameters['--member'] = 0;
        }

        if ($name === 'fund:reconcile' && isset($parameters['--daily'])) {
            $parameters['--no-store'] = true;
        }

        try {
            $exit = Artisan::call($name, $parameters);
        } catch (Throwable $e) {
            expect($e->getMessage())->not->toContain('fundflow_central.settings');

            throw $e;
        }

        expect($exit)->toBeIn([0, 1], "Job [{$definition['key']}] exited with unexpected code {$exit}");
    }
})->group('slow');
