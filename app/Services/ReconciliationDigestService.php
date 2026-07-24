<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Pages\ReconciliationOverviewPage;
use App\Models\Tenant\ReconciliationSnapshot;
use App\Models\Tenant\User;
use App\Notifications\Tenant\ReconciliationDigestNotification;
use App\Support\AutomationScheduleSettings;
use App\Support\MemberLocale;

class ReconciliationDigestService
{
    /**
     * @param  array{halted?: bool, raised?: int, resolved?: int, critical?: int}  $result
     */
    public function notifyAdminsOfNightlyBatch(array $result): int
    {
        if (! AutomationScheduleSettings::notifyReconciliationDigest()) {
            return 0;
        }

        $halted = (bool) ($result['halted'] ?? false);

        return $this->dispatch(
            'nightly',
            fn (): string => $halted
                ? __('Halted on a critical master imbalance. Raised :raised · resolved :resolved · critical :critical.', [
                    'raised' => (int) ($result['raised'] ?? 0),
                    'resolved' => (int) ($result['resolved'] ?? 0),
                    'critical' => (int) ($result['critical'] ?? 0),
                ])
                : __('Raised :raised · resolved :resolved · critical :critical.', [
                    'raised' => (int) ($result['raised'] ?? 0),
                    'resolved' => (int) ($result['resolved'] ?? 0),
                    'critical' => (int) ($result['critical'] ?? 0),
                ]),
            ReconciliationOverviewPage::getUrl(['sideTab' => 'exceptions']),
            $halted || (int) ($result['critical'] ?? 0) > 0,
        );
    }

    /**
     * @param  ReconciliationSnapshot::MODE_*  $snapshotMode
     * @param  array<string, mixed>  $report
     */
    public function notifyAdminsOfReport(string $snapshotMode, array $report): int
    {
        if (! AutomationScheduleSettings::notifyReconciliationDigest()) {
            return 0;
        }

        $mode = $snapshotMode === ReconciliationSnapshot::MODE_MONTHLY ? 'monthly' : 'daily';

        $verdict = is_array($report['verdict'] ?? null) ? $report['verdict'] : [];
        $pass = (bool) ($verdict['pass'] ?? false);
        $criticalIssues = (int) ($verdict['critical_issues'] ?? 0);
        $warnings = (int) ($verdict['warnings'] ?? 0);
        $ledgerMismatches = (int) ($report['checks']['ledger_balances']['mismatch_count'] ?? 0);
        $openExceptions = (int) ($report['control_layer']['open_exception_count'] ?? 0);

        return $this->dispatch(
            $mode,
            fn (): string => $pass
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
                ]),
            ReconciliationOverviewPage::getUrl(['sideTab' => 'snapshots']),
            ! $pass || $criticalIssues > 0,
        );
    }

    /**
     * @param  callable(): string  $summaryForLocale
     */
    private function dispatch(string $mode, callable $summaryForLocale, string $url, bool $critical): int
    {
        $notified = 0;

        User::query()
            ->where('is_admin', true)
            ->each(function (User $admin) use ($mode, $summaryForLocale, $url, $critical, &$notified): void {
                MemberLocale::usingPreferred($admin, function () use ($admin, $mode, $summaryForLocale, $url, $critical): void {
                    $admin->notify(new ReconciliationDigestNotification(
                        $mode,
                        $summaryForLocale(),
                        $url,
                        $critical,
                    ));
                });

                $notified++;
            });

        return $notified;
    }
}
