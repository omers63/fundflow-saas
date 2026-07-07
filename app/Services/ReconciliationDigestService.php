<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Pages\ReconciliationOverviewPage;
use App\Models\Tenant\ReconciliationSnapshot;
use App\Models\Tenant\User;
use App\Notifications\Tenant\ReconciliationDigestNotification;

class ReconciliationDigestService
{
    /**
     * @param  array{halted?: bool, raised?: int, resolved?: int, critical?: int}  $result
     */
    public function notifyAdminsOfNightlyBatch(array $result): int
    {
        $raised = (int) ($result['raised'] ?? 0);
        $resolved = (int) ($result['resolved'] ?? 0);
        $critical = (int) ($result['critical'] ?? 0);
        $halted = (bool) ($result['halted'] ?? false);

        $summary = $halted
            ? __('Halted on a critical master imbalance. Raised :raised · resolved :resolved · critical :critical.', [
                'raised' => $raised,
                'resolved' => $resolved,
                'critical' => $critical,
            ])
            : __('Raised :raised · resolved :resolved · critical :critical.', [
                'raised' => $raised,
                'resolved' => $resolved,
                'critical' => $critical,
            ]);

        return $this->dispatch(
            'nightly',
            $summary,
            ReconciliationOverviewPage::getUrl(['sideTab' => 'exceptions']),
            $halted || $critical > 0,
        );
    }

    /**
     * @param  ReconciliationSnapshot::MODE_*  $snapshotMode
     * @param  array<string, mixed>  $report
     */
    public function notifyAdminsOfReport(string $snapshotMode, array $report): int
    {
        $mode = $snapshotMode === ReconciliationSnapshot::MODE_MONTHLY ? 'monthly' : 'daily';

        $verdict = is_array($report['verdict'] ?? null) ? $report['verdict'] : [];
        $pass = (bool) ($verdict['pass'] ?? false);
        $criticalIssues = (int) ($verdict['critical_issues'] ?? 0);
        $warnings = (int) ($verdict['warnings'] ?? 0);
        $ledgerMismatches = (int) ($report['checks']['ledger_balances']['mismatch_count'] ?? 0);
        $openExceptions = (int) ($report['control_layer']['open_exception_count'] ?? 0);

        $summary = $pass
            ? __('Passed. Warnings :warnings · ledger mismatches :ledger · open exceptions :open.', [
                'warnings' => $warnings,
                'ledger' => $ledgerMismatches,
                'open' => $openExceptions,
            ])
            : __('Failed. Critical :critical · warnings :warnings · ledger mismatches :ledger · open exceptions :open.', [
                'critical' => $criticalIssues,
                'warnings' => $warnings,
                'ledger' => $ledgerMismatches,
                'open' => $openExceptions,
            ]);

        return $this->dispatch(
            $mode,
            $summary,
            ReconciliationOverviewPage::getUrl(['sideTab' => 'snapshots']),
            ! $pass || $criticalIssues > 0,
        );
    }

    private function dispatch(string $mode, string $summary, string $url, bool $critical): int
    {
        $notification = new ReconciliationDigestNotification($mode, $summary, $url, $critical);

        $notified = 0;

        User::query()
            ->where('is_admin', true)
            ->each(function (User $admin) use ($notification, &$notified): void {
                $admin->notify(clone $notification);
                $notified++;
            });

        return $notified;
    }
}
