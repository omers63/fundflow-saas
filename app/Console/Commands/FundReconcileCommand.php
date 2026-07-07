<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\TenantAwareScheduledCommand;
use App\Models\Tenant\ReconciliationSnapshot;
use App\Services\ReconciliationDigestService;
use App\Services\ReconciliationReportService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Console\Command;

class FundReconcileCommand extends Command
{
    use TenantAwareScheduledCommand;

    protected $signature = 'fund:reconcile
        {--realtime : Point-in-time reconciliation as of now}
        {--daily : Calendar-day window (yesterday) plus full ledger checks}
        {--monthly : Previous calendar month window plus full ledger checks}
        {--no-store : Print summary only; do not write reconciliation_snapshots}';

    protected $description = 'Run financial reconciliation report and optionally store an audit snapshot';

    public function handle(ReconciliationReportService $service): int
    {
        @set_time_limit(0);

        $tz = config('app.timezone');
        $now = Carbon::now($tz);

        if ($this->option('realtime')) {
            $mode = ReconciliationSnapshot::MODE_REALTIME;
            $asOf = $now;
            $periodStart = null;
            $periodEnd = null;
        } elseif ($this->option('monthly')) {
            $mode = ReconciliationSnapshot::MODE_MONTHLY;
            $asOf = $now;
            $anchor = $now->copy()->subMonthNoOverflow();
            $periodStart = $anchor->copy()->startOfMonth();
            $periodEnd = $anchor->copy()->endOfMonth();
        } else {
            $mode = ReconciliationSnapshot::MODE_DAILY;
            $asOf = $now;
            $periodStart = $now->copy()->subDay()->startOfDay();
            $periodEnd = $now->copy()->subDay()->endOfDay();
        }

        $options = ReconciliationReportService::bankOptionsFromSettings();
        $report = $service->buildReport($mode, $asOf, $periodStart, $periodEnd, $options);

        $verdict = $report['verdict'];
        $this->line('Mode: '.$mode);
        $this->line('As of: '.$report['meta']['as_of']);

        if ($periodStart && $periodEnd) {
            $this->line('Period: '.$periodStart->toIso8601String().' → '.$periodEnd->toIso8601String());
        }

        $this->line('Pass: '.($verdict['pass'] ? 'yes' : 'no'));
        $this->line('Critical: '.$verdict['critical_issues'].' | Warnings: '.$verdict['warnings']);
        $this->line('Ledger mismatches: '.$report['checks']['ledger_balances']['mismatch_count']);
        $this->line('Unposted bank rows: '.$report['pipeline']['bank_unposted_count']);
        $this->line('Open control exceptions: '.($report['control_layer']['open_exception_count'] ?? 0));

        foreach ($report['coverage_matrix'] ?? [] as $row) {
            $pairs = [];
            foreach ($row['checks'] ?? [] as $check) {
                $pairs[] = (($check['key'] ?? '?').'='.($check['severity'] ?? '?'));
            }
            $this->line('Coverage: '.($row['flow'] ?? '?').' → '.implode(', ', $pairs));
        }

        if (! $this->option('no-store')) {
            $snapshot = $service->persistSnapshot($report, null);
            $this->line('Snapshot #'.$snapshot->id.' stored.');
        }

        if (in_array($mode, [ReconciliationSnapshot::MODE_DAILY, ReconciliationSnapshot::MODE_MONTHLY], true)) {
            Filament::setCurrentPanel('tenant');
            app(ReconciliationDigestService::class)->notifyAdminsOfReport($mode, $report);
        }

        return $verdict['pass'] ? self::SUCCESS : self::FAILURE;
    }
}
