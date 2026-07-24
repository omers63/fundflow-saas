<?php

namespace App\Console\Commands;

use App\Console\Concerns\EnsuresBatchPostingAllowed;
use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\BankClearingMatchService;
use App\Support\AutomationScheduleSettings;
use Illuminate\Console\Command;

class BankAutoMatchCommand extends Command
{
    use EnsuresBatchPostingAllowed;
    use TenantAwareScheduledCommand;

    protected $signature = 'bank:auto-match
        {--force : Run even when not in the configured daily slot}';

    protected $description = 'Auto-match imported bank lines to uncleared cash postings';

    public function handle(BankClearingMatchService $matching): int
    {
        if (! $this->option('force') && ! AutomationScheduleSettings::isBankAutoMatchSlot()) {
            $this->skipScheduledRunRecording = true;
            $this->info(__('Skipped: bank auto-match runs at :time.', [
                'time' => AutomationScheduleSettings::bankAutoMatchTime(),
            ]));

            return self::SUCCESS;
        }

        if (! $this->ensureBatchPostingAllowed()) {
            return self::SUCCESS;
        }
        $stats = $matching->autoMatchImportedLines();

        $this->info(__('Matched: :matched, Ambiguous: :ambiguous, Unmatched: :unmatched', $stats));

        return self::SUCCESS;
    }
}
