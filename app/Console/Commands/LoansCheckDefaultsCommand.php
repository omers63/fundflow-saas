<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\Loans\LoanDelinquencyService;
use App\Support\AutomationScheduleSettings;
use Illuminate\Console\Command;

class LoansCheckDefaultsCommand extends Command
{
    use TenantAwareScheduledCommand;

    protected $signature = 'loans:check-defaults
        {--force : Run even when not in the configured loan-defaults slot}';

    protected $description = 'Mark overdue installments, sync member delinquency, and process guarantor defaults';

    public function handle(LoanDelinquencyService $delinquency): int
    {
        if (! $this->option('force') && ! AutomationScheduleSettings::isLoanDefaultsSlot()) {
            $this->skipScheduledRunRecording = true;
            $this->info(__('Skipped: loan delinquency check runs at :time when enabled.', [
                'time' => AutomationScheduleSettings::loanDefaultsTime(),
            ]));

            return self::SUCCESS;
        }

        if (! AutomationScheduleSettings::loanDefaultsEnabled() && ! $this->option('force')) {
            $this->skipScheduledRunRecording = true;
            $this->info(__('Skipped: loan delinquency check automation is disabled in settings.'));

            return self::SUCCESS;
        }

        $result = $delinquency->runDailyMaintenance();

        $this->info(__('Overdue marked: :overdue, delinquent members: :delinquent, cleared: :cleared, warnings: :warned, guarantor debits: :debited, auto-transfers: :transferred', [
            'overdue' => $result['marked_overdue'],
            'delinquent' => $result['delinquent_count'],
            'cleared' => $result['cleared_count'],
            'warned' => $result['warned'],
            'debited' => $result['debited_from_guarantor'],
            'transferred' => $result['transferred_to_guarantor'] ?? 0,
        ]));

        return self::SUCCESS;
    }
}
