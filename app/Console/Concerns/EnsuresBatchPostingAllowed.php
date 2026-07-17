<?php

declare(strict_types=1);

namespace App\Console\Concerns;

use App\Support\BatchPostingGate;

trait EnsuresBatchPostingAllowed
{
    /**
     * Whether batch posting may proceed for the current tenant.
     *
     * When halted, logs a warning and returns false so callers can soft-skip
     * with SUCCESS (avoids multi-tenant schedule failures for frozen tenants).
     */
    protected function ensureBatchPostingAllowed(): bool
    {
        try {
            app(BatchPostingGate::class)->assertAllowed();
        } catch (\InvalidArgumentException $exception) {
            $this->warn($exception->getMessage());
            $this->warn(__('Skipping :command — batch posting is halted for this tenant.', [
                'command' => $this->getName() ?? 'command',
            ]));

            return false;
        }

        return true;
    }
}
