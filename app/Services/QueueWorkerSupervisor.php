<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\PhpExecutableFinder;

final class QueueWorkerSupervisor
{
    public function isEnabled(): bool
    {
        return (bool) config('queue.worker_watchdog.enabled', true);
    }

    public function shouldSupervise(): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        return config('queue.default') !== 'sync';
    }

    public function isWorkerRunning(): bool
    {
        if (! $this->shouldSupervise()) {
            return true;
        }

        $result = Process::run(['pgrep', '-f', $this->workerProcessPattern()]);

        return $result->successful() && trim($result->output()) !== '';
    }

    /**
     * @return array{restarted: bool, started: bool}
     */
    public function ensureWorkerRunning(): array
    {
        if (! $this->shouldSupervise()) {
            return ['restarted' => false, 'started' => false];
        }

        if ($this->isWorkerRunning()) {
            return ['restarted' => false, 'started' => false];
        }

        Artisan::call('queue:restart');

        $this->startWorkerInBackground();

        return ['restarted' => true, 'started' => true];
    }

    /**
     * @return list<string>
     */
    public function workerCommandArguments(): array
    {
        $connection = (string) (config('queue.worker_watchdog.connection') ?? config('queue.default'));

        return [
            'queue:work',
            $connection,
            '--sleep='.(int) config('queue.worker_watchdog.sleep', 3),
            '--tries='.(int) config('queue.worker_watchdog.tries', 3),
            '--max-time='.(int) config('queue.worker_watchdog.max_time', 3600),
            '--backoff='.(int) config('queue.worker_watchdog.backoff', 10),
        ];
    }

    public function workerProcessPattern(): string
    {
        $arguments = implode(' ', $this->workerCommandArguments());

        return preg_quote(base_path('artisan'), '/').'.*'.preg_quote($arguments, '/');
    }

    protected function startWorkerInBackground(): void
    {
        $phpBinary = (new PhpExecutableFinder)->find(false) ?: PHP_BINARY;

        Process::path(base_path())
            ->start(array_merge([$phpBinary, 'artisan'], $this->workerCommandArguments()));
    }
}
