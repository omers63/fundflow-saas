<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Services\Loans\LoanManualScheduleGracePushService;
use App\Services\Loans\LoanSingleOverdueGraceShiftService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Manual schedule/grace remediation (Jobs UI → Run now). Not on the recurring schedule.
 *
 * - With --loan: push that loan's unpaid schedule N cycles and extend grace.
 * - Without --loan: bulk-shift loans with no beginning grace and exactly one overdue cycle.
 */
class LoansPushScheduleGraceCommand extends Command
{
    use TenantAwareScheduledCommand;

    protected $signature = 'loans:push-schedule-grace
        {--loan= : Optional loan id. Omit to shift all eligible single-overdue loans}
        {--cycles=1 : Number of cycles to push unpaid schedules forward}
        {--dry-run : Preview without writing}
        {--force : Required for Jobs UI / manual execution}';

    protected $description = 'Push unpaid loan schedule(s) N cycles forward and apply grace (one loan, or all eligible single-overdue loans)';

    public function handle(
        LoanManualScheduleGracePushService $pushes,
        LoanSingleOverdueGraceShiftService $shifts,
    ): int {
        if (! $this->option('force') && ! $this->option('dry-run')) {
            $this->skipScheduledRunRecording = true;
            $this->info(__('Skipped: this one-time job only runs from Jobs → Run now (or with --force / --dry-run).'));

            return self::SUCCESS;
        }

        $loanOption = $this->option('loan');
        $loanId = filled($loanOption) ? (int) $loanOption : null;
        $dryRun = (bool) $this->option('dry-run');
        $cycles = LoanManualScheduleGracePushService::clampCycles((int) $this->option('cycles'));

        if ($loanId !== null) {
            return $this->pushOneLoan($pushes, $loanId, $cycles, $dryRun);
        }

        return $this->shiftEligibleSingleOverdue($shifts, $cycles, $dryRun);
    }

    private function pushOneLoan(
        LoanManualScheduleGracePushService $pushes,
        int $loanId,
        int $cycles,
        bool $dryRun,
    ): int {
        try {
            $stats = $pushes->push($loanId, $cycles, $dryRun);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $payload = [
            'id' => $stats['loan_id'],
            'cycles' => $stats['cycles'],
            'installments' => $stats['installments'],
            'from_grace' => $stats['previous_grace_cycles'],
            'to_grace' => $stats['new_grace_cycles'],
            'from_first' => $stats['previous_first_repayment'] ?? '—',
            'to_first' => $stats['new_first_repayment'] ?? '—',
        ];

        if ($dryRun) {
            $this->info(__('Dry run: would push loan #:id by :cycles cycle(s), :installments installment(s); grace :from_grace → :to_grace; first repayment :from_first → :to_first.', $payload));
        } else {
            $this->info(__('Pushed loan #:id by :cycles cycle(s), :installments installment(s); grace :from_grace → :to_grace; first repayment :from_first → :to_first.', $payload));
        }

        return self::SUCCESS;
    }

    private function shiftEligibleSingleOverdue(
        LoanSingleOverdueGraceShiftService $shifts,
        int $cycles,
        bool $dryRun,
    ): int {
        $stats = $shifts->shiftEligibleLoans($dryRun, null, $cycles);

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
