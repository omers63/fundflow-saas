<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\Loans\LoanSingleOverdueGraceShiftService;
use Illuminate\Console\Command;

/**
 * Manual one-time remediation (Jobs UI → Run now). Not on the recurring schedule.
 */
class LoansShiftSingleOverdueGraceCommand extends Command
{
    use TenantAwareScheduledCommand;

    protected $signature = 'loans:shift-single-overdue-grace
        {--loan= : Limit to a single loan id}
        {--grace-cycles=1 : Number of cycles to push the unpaid schedule forward}
        {--dry-run : Preview eligible loans without writing}
        {--force : Required for Jobs UI / manual execution}';

    protected $description = 'Push unpaid schedules N cycles forward for loans with no beginning grace and exactly one overdue cycle';

    public function handle(LoanSingleOverdueGraceShiftService $shifts): int
    {
        if (! $this->option('force') && ! $this->option('dry-run')) {
            $this->skipScheduledRunRecording = true;
            $this->info(__('Skipped: this one-time job only runs from Jobs → Run now (or with --force / --dry-run).'));

            return self::SUCCESS;
        }

        $loanOption = $this->option('loan');
        $loanId = filled($loanOption) ? (int) $loanOption : null;
        $dryRun = (bool) $this->option('dry-run');
        $graceCycles = LoanSingleOverdueGraceShiftService::clampGraceCycles((int) $this->option('grace-cycles'));

        $stats = $shifts->shiftEligibleLoans($dryRun, $loanId, $graceCycles);

        if ($dryRun) {
            $this->info(__('Dry run: would shift :loans loan(s) by :cycles cycle(s), :installments installment(s); skipped :skipped.', [
                'loans' => $stats['shifted'],
                'cycles' => $stats['grace_cycles'],
                'installments' => $stats['installments'],
                'skipped' => $stats['skipped'],
            ]));
        } else {
            $this->info(__('Shifted :loans loan(s) by :cycles cycle(s), :installments installment(s); skipped :skipped.', [
                'loans' => $stats['shifted'],
                'cycles' => $stats['grace_cycles'],
                'installments' => $stats['installments'],
                'skipped' => $stats['skipped'],
            ]));
        }

        if ($stats['loan_ids'] !== []) {
            $this->line(__('Loan IDs: :ids', [
                'ids' => implode(', ', array_map(fn (int $id): string => '#'.$id, $stats['loan_ids'])),
            ]));
        }

        return self::SUCCESS;
    }
}
