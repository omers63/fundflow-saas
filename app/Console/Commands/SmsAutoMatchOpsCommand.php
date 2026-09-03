<?php

namespace App\Console\Commands;

use App\Console\Concerns\EnsuresBatchPostingAllowed;
use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\SmsOperationalClearingMatchService;
use App\Support\AutomationScheduleSettings;
use App\Support\EvidenceChannelSettings;
use Illuminate\Console\Command;

class SmsAutoMatchOpsCommand extends Command
{
    use EnsuresBatchPostingAllowed;
    use TenantAwareScheduledCommand;

    protected $signature = 'sms:auto-match-ops
        {--force : Run even when not in the configured daily slot}';

    protected $description = 'Auto-match posted SMS rows to uncleared operational bank rows (1:1 only)';

    public function handle(SmsOperationalClearingMatchService $matching): int
    {
        if (! EvidenceChannelSettings::usesSms()) {
            $this->skipScheduledRunRecording = true;
            $this->info(__('Skipped: SMS operational auto-match requires an SMS evidence channel.'));

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! AutomationScheduleSettings::isBankAutoMatchSlot()) {
            $this->skipScheduledRunRecording = true;
            $this->info(__('Skipped: :schedule.', [
                'schedule' => AutomationScheduleSettings::bankAutoMatchScheduleLabel(),
            ]));

            return self::SUCCESS;
        }

        if (! $this->ensureBatchPostingAllowed()) {
            return self::SUCCESS;
        }

        $stats = $matching->autoMatchUniquePairs();

        $this->info(__('Matched: :matched, Ambiguous: :ambiguous, Unmatched ops: :unmatched', [
            'matched' => $stats['matched'],
            'ambiguous' => $stats['ambiguous'],
            'unmatched' => $stats['unmatched_ops'],
        ]));

        return self::SUCCESS;
    }
}
