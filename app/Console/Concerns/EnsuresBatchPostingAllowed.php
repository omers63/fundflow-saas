<?php

declare(strict_types=1);

namespace App\Console\Concerns;

use App\Support\BatchPostingGate;

trait EnsuresBatchPostingAllowed
{
    protected function ensureBatchPostingAllowed(): int
    {
        try {
            app(BatchPostingGate::class)->assertAllowed();
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
