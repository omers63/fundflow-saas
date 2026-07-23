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
     * When true, skip CommandStarting/Finished recording and per-tenant scheduled inserts
     * (manual runs via SystemJobRunnerService already own the SystemJobRun row).
     */
    private static bool $recordingSuppressed = false;

    /**
     * In-process handoff between CommandStarting and CommandFinished.
     * Avoids the tenant cache wrapper, which requires a tag-capable store (e.g. Redis).
     *
     * @var array<string, array{job_key: string, command: string, started_at: float}>
     */
    private static array $pendingCommandRuns = [];

    public static function suppressRecording(): void
    {
        self::$recordingSuppressed = true;
    }

    public static function resumeRecording(): void
    {
        self::$recordingSuppressed = false;
    }

    public static function recordingSuppressed(): bool
    {
        return self::$recordingSuppressed;
    }

    public function handleStarting(CommandStarting $event): void
    {
        if (self::$recordingSuppressed || ! tenancy()->initialized) {
            return;
        }

        $definition = ScheduledJobRegistry::findForInput($event->command, $event->input);

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
        $runKey = $this->runKey($event->command);
        $payload = self::$pendingCommandRuns[$runKey] ?? null;
        unset(self::$pendingCommandRuns[$runKey]);

        if (self::$recordingSuppressed || ! tenancy()->initialized || $payload === null) {
            return;
        }

        $startedAt = (float) ($payload['started_at'] ?? microtime(true));
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        self::recordCompletedRun(
            jobKey: $payload['job_key'],
            command: $payload['command'],
            exitCode: $event->exitCode,
            durationMs: $durationMs,
            trigger: SystemJobRun::TRIGGER_SCHEDULE,
        );
    }

    public static function recordCompletedRun(
        string $jobKey,
        string $command,
        int $exitCode,
        int $durationMs,
        string $trigger = SystemJobRun::TRIGGER_SCHEDULE,
    ): void {
        if (! tenancy()->initialized) {
            return;
        }

        SystemJobRun::create([
            'job_key' => $jobKey,
            'command' => $command,
            'trigger' => $trigger,
            'status' => $exitCode === 0 ? SystemJobRun::STATUS_SUCCESS : SystemJobRun::STATUS_FAILED,
            'exit_code' => $exitCode,
            'started_at' => now()->subMilliseconds(max(0, $durationMs)),
            'finished_at' => now(),
            'duration_ms' => $durationMs,
            'summary' => ['exit_code' => $exitCode],
        ]);
    }

    protected function runKey(string $commandName): string
    {
        $tenantId = tenancy()->initialized ? (string) tenant('id') : 'central';

        return $tenantId.':'.$commandName;
    }
}
