<?php

declare(strict_types=1);

namespace App\Services;

use App\Listeners\RecordSystemJobRunListener;
use App\Models\Tenant\SystemJobRun;
use App\Models\Tenant\User;
use App\Support\BatchPostingGate;
use App\Support\ScheduledJobRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Symfony\Component\Console\Output\BufferedOutput;

class SystemJobRunnerService
{
    public function __construct(
        protected BatchPostingGate $batchGate,
    ) {}

    /**
     * @param  array<string, mixed>  $extraParameters
     * @return array{run: SystemJobRun, exit_code: int}
     *
     * @throws InvalidArgumentException
     */
    public function run(string $jobKey, string $trigger = SystemJobRun::TRIGGER_MANUAL, ?User $user = null, array $extraParameters = []): array
    {
        $definition = ScheduledJobRegistry::find($jobKey);

        if ($definition === null) {
            throw new InvalidArgumentException(__('Unknown job: :key', ['key' => $jobKey]));
        }

        if ($definition['halt_sensitive'] && $this->batchGate->isHalted()) {
            throw new InvalidArgumentException(
                $this->batchGate->reason() ?? __('Batch posting is halted. Resolve critical reconciliation first.')
            );
        }

        $user ??= Auth::guard('tenant')->user();

        $run = SystemJobRun::create([
            'job_key' => $jobKey,
            'command' => $definition['command'],
            'trigger' => $trigger,
            'status' => SystemJobRun::STATUS_RUNNING,
            'started_at' => now(),
            'triggered_by' => $user?->id,
        ]);

        $output = new BufferedOutput;
        $started = microtime(true);
        [$artisanCommand, $commandParameters] = $this->parseCommandInvocation($definition['command']);
        $parameters = array_merge(
            $commandParameters,
            $this->artisanParametersFor($artisanCommand),
            $this->normalizeExtraParameters($artisanCommand, $extraParameters),
        );

        RecordSystemJobRunListener::suppressRecording();

        try {
            @set_time_limit(0);
            $exitCode = Artisan::call($artisanCommand, $parameters, $output);
        } catch (\Throwable $exception) {
            $exitCode = 1;
            $output->writeln($exception->getMessage());
        } finally {
            RecordSystemJobRunListener::resumeRecording();
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $text = $output->fetch();

        $run->update([
            'status' => $exitCode === 0 ? SystemJobRun::STATUS_SUCCESS : SystemJobRun::STATUS_FAILED,
            'exit_code' => $exitCode,
            'finished_at' => now(),
            'duration_ms' => $durationMs,
            'output' => mb_substr($text, 0, 65000),
            'summary' => [
                'exit_code' => $exitCode,
                'duration_ms' => $durationMs,
            ],
        ]);

        return ['run' => $run->fresh(), 'exit_code' => $exitCode];
    }

    public function latestRun(string $jobKey): ?SystemJobRun
    {
        return SystemJobRun::query()
            ->forJob($jobKey)
            ->latestFirst()
            ->first();
    }

    /**
     * Filament custom-table records keyed by job key (see tables custom-data docs).
     *
     * @return Collection<string, array<string, mixed>>
     */
    public function catalogRecords(): Collection
    {
        return collect(ScheduledJobRegistry::all())
            ->mapWithKeys(fn (array $definition): array => [
                $definition['key'] => $this->catalogRow($definition),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function catalogRow(array $definition): array
    {
        $latest = $this->latestRun($definition['key']);

        return [
            'key' => $definition['key'],
            'job_label' => $definition['label'],
            'description' => $definition['description'],
            'command' => $definition['command'],
            'schedule' => $definition['schedule'],
            'category' => $definition['category'],
            'halt_sensitive' => $definition['halt_sensitive'],
            'last_status' => $latest?->status,
            'last_started_at' => $latest?->started_at,
            'last_duration_ms' => $latest?->duration_ms,
            'last_exit_code' => $latest?->exit_code,
        ];
    }

    /**
     * Split a registry command string (e.g. "fund:reconcile --daily") into the
     * Artisan command name plus option parameters.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    protected function parseCommandInvocation(string $command): array
    {
        $parts = preg_split('/\s+/', trim($command)) ?: [];
        $name = (string) array_shift($parts);
        $parameters = [];

        foreach ($parts as $part) {
            if (! str_starts_with($part, '--')) {
                continue;
            }

            $flag = substr($part, 2);
            $eqPos = strpos($flag, '=');

            if ($eqPos === false) {
                $parameters['--'.$flag] = true;

                continue;
            }

            $parameters['--'.substr($flag, 0, $eqPos)] = substr($flag, $eqPos + 1);
        }

        return [$name, $parameters];
    }

    /**
     * @return array<string, mixed>
     */
    protected function artisanParametersFor(string $command): array
    {
        if (! tenancy()->initialized || blank(tenant('id'))) {
            return [];
        }

        $name = explode(' ', trim($command), 2)[0];

        try {
            $definition = Artisan::all()[$name]?->getDefinition();
        } catch (\Throwable) {
            return [];
        }

        if ($definition === null || ! $definition->hasOption('tenants')) {
            $parameters = [];
        } else {
            $parameters = [
                '--tenants' => [(string) tenant('id')],
            ];
        }

        if ($definition !== null && $definition->hasOption('force')) {
            $parameters['--force'] = true;
        }

        return $parameters;
    }

    /**
     * @param  array<string, mixed>  $extraParameters
     * @return array<string, mixed>
     */
    protected function normalizeExtraParameters(string $command, array $extraParameters): array
    {
        if ($extraParameters === []) {
            return [];
        }

        $name = explode(' ', trim($command), 2)[0];

        try {
            $definition = Artisan::all()[$name]?->getDefinition();
        } catch (\Throwable) {
            return [];
        }

        if ($definition === null) {
            return [];
        }

        $normalized = [];

        foreach ($extraParameters as $key => $value) {
            $option = str_starts_with((string) $key, '--') ? (string) $key : '--'.$key;

            if (! $definition->hasOption(ltrim($option, '-'))) {
                continue;
            }

            $normalized[$option] = $value;
        }

        return $normalized;
    }

    /**
     * Delete finished run-history rows for the current tenant.
     * Rows still marked running are kept so an in-flight job is not orphaned.
     */
    public function clearRunHistory(): int
    {
        return (int) SystemJobRun::query()
            ->where('status', '!=', SystemJobRun::STATUS_RUNNING)
            ->delete();
    }
}
