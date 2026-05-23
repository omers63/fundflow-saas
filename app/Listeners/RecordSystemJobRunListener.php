<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\Tenant\SystemJobRun;
use App\Support\ScheduledJobRegistry;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;

class RecordSystemJobRunListener
{
    /**
     * In-process handoff between CommandStarting and CommandFinished.
     * Avoids the tenant cache wrapper, which requires a tag-capable store (e.g. Redis).
     *
     * @var array<string, array{job_key: string, command: string, started_at: float}>
     */
    private static array $pendingCommandRuns = [];

    public function handleStarting(CommandStarting $event): void
    {
        if (!tenancy()->initialized) {
            return;
        }

        $definition = $this->matchDefinition($event->command);

        if ($definition === null) {
            return;
        }

        self::$pendingCommandRuns[$this->runKey($event->command)] = [
            'job_key' => $definition['key'],
            'command' => $definition['command'],
            'started_at' => microtime(true),
        ];
    }

    public function handleFinished(CommandFinished $event): void
    {
        if (!tenancy()->initialized) {
            return;
        }

        $runKey = $this->runKey($event->command);
        $payload = self::$pendingCommandRuns[$runKey] ?? null;
        unset(self::$pendingCommandRuns[$runKey]);

        if ($payload === null) {
            return;
        }

        $startedAt = (float) ($payload['started_at'] ?? microtime(true));
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        SystemJobRun::create([
            'job_key' => $payload['job_key'],
            'command' => $payload['command'],
            'trigger' => SystemJobRun::TRIGGER_SCHEDULE,
            'status' => $event->exitCode === 0 ? SystemJobRun::STATUS_SUCCESS : SystemJobRun::STATUS_FAILED,
            'exit_code' => $event->exitCode,
            'started_at' => now()->subMilliseconds($durationMs),
            'finished_at' => now(),
            'duration_ms' => $durationMs,
            'summary' => ['exit_code' => $event->exitCode],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function matchDefinition(string $commandName): ?array
    {
        foreach (ScheduledJobRegistry::all() as $definition) {
            $base = explode(' ', $definition['command'])[0];

            if ($base === $commandName) {
                return $definition;
            }
        }

        return null;
    }

    protected function runKey(string $commandName): string
    {
        return tenant('id') . ':' . $commandName;
    }
}
