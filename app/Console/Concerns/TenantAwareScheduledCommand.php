<?php

declare(strict_types=1);

namespace App\Console\Concerns;

use App\Listeners\RecordSystemJobRunListener;
use App\Support\AutomationSchedulerGate;
use App\Support\ScheduledJobRegistry;
use Illuminate\Support\LazyCollection;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Stancl\Tenancy\Concerns\TenantAwareCommand;
use Stancl\Tenancy\Contracts\Tenant;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs an Artisan command once per tenant database (for cron / schedule:run)
 * and records each tenant execution in system_job_runs.
 */
trait TenantAwareScheduledCommand
{
    use HasATenantsOption;
    use TenantAwareCommand;

    /**
     * When true, this tenant execution is a no-op (e.g. not cycle start day) and should
     * not create a system_job_runs row.
     */
    protected bool $skipScheduledRunRecording = false;

    /**
     * @return LazyCollection<int, Tenant>
     */
    protected function getTenants(): LazyCollection
    {
        $explicit = $this->option('tenants');
        $explicit = is_array($explicit)
            ? array_values(array_filter(array_map('strval', $explicit), static fn(string $id): bool => $id !== ''))
            : [];

        $fromEnv = array_values(array_filter(array_map(
            static fn(string $id): string => trim($id),
            explode(',', (string) env('SCHEDULE_TENANT_IDS', '')),
        ), static fn(string $id): bool => $id !== ''));

        $ids = $explicit !== [] ? $explicit : $fromEnv;

        return tenancy()
            ->query()
            ->when($ids !== [], function ($query) use ($ids): void {
                $query->whereIn(tenancy()->model()->getTenantKeyName(), $ids);
            })
            ->cursor();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $definition = ScheduledJobRegistry::findForCommand($this);
        $tenants = $this->getTenants();
        $exitCode = 0;

        foreach ($tenants as $tenant) {
            $started = microtime(true);

            $result = (int) $tenant->run(function () use ($definition, $started) {
                $this->skipScheduledRunRecording = false;

                if (app(AutomationSchedulerGate::class)->isPaused()) {
                    $this->skipScheduledRunRecording = true;
                    $this->warn(__('Skipping :command — scheduled automation is paused for this tenant.', [
                        'command' => $this->getName() ?? 'command',
                    ]));

                    return self::SUCCESS;
                }

                $result = (int) $this->laravel->call([$this, 'handle']);

                if (
                    $definition !== null
                    && ! $this->skipScheduledRunRecording
                    && ! RecordSystemJobRunListener::recordingSuppressed()
                ) {
                    $durationMs = (int) round((microtime(true) - $started) * 1000);

                    RecordSystemJobRunListener::recordCompletedRun(
                        jobKey: $definition['key'],
                        command: $definition['command'],
                        exitCode: $result,
                        durationMs: $durationMs,
                    );
                }

                return $result;
            });

            if ($result !== 0) {
                $exitCode = $result;
            }
        }

        return $exitCode;
    }
}
