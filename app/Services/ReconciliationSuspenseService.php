<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\ReconciliationException;
use App\Support\ContributionPolicySettings;
use InvalidArgumentException;

class ReconciliationSuspenseService
{
    public function __construct(
        protected AccountingService $accounting,
        protected FundAuditLogService $audit,
    ) {}

    public function ensureSuspenseAccount(): Account
    {
        $existing = Account::query()
            ->where('is_master', true)
            ->where('type', 'suspense')
            ->first();

        if ($existing) {
            return $existing;
        }

        return Account::create([
            'type' => 'suspense',
            'name' => __('Reconciliation suspense'),
            'balance' => 0,
            'is_master' => true,
        ]);
    }

    /**
     * Post rounding adjustment per spec (DR suspense, CR master cash or fund).
     */
    public function postRoundingAdjustment(float $delta, string $target = 'cash'): void
    {
        if (abs($delta) <= 0.00001) {
            return;
        }

        $suspense = $this->ensureSuspenseAccount();
        $targetAccount = $target === 'fund' ? Account::masterFund() : Account::masterCash();

        if ($targetAccount === null) {
            throw new InvalidArgumentException(__('Master account not configured for rounding adjustment.'));
        }

        $description = __('Reconciliation rounding adjustment');

        if ($delta > 0) {
            $this->accounting->debit($suspense, $delta, $description);
            $this->accounting->credit($targetAccount, $delta, $description);
        } else {
            $amount = abs($delta);
            $this->accounting->debit($targetAccount, $amount, $description);
            $this->accounting->credit($suspense, $amount, $description);
        }

        $this->audit->log('RECON_ROUNDING_ADJUSTMENT', 'reconciliation', payload: [
            'delta' => $delta,
            'target' => $target,
        ]);
    }

    public function deferTimingException(ReconciliationException $exception, ?int $hours = null): void
    {
        $hours ??= ContributionPolicySettings::timingDiffDeferHours();

        $exception->update([
            'exception_type' => 'timing_difference',
            'deferred_until' => now()->addHours($hours),
            'auto_resolve_reason' => __('Deferred :hours hours for in-flight match', ['hours' => $hours]),
        ]);
    }

    public function isDeferred(ReconciliationException $exception): bool
    {
        return $exception->deferred_until !== null && now()->lt($exception->deferred_until);
    }

    public function escalateDeferredExceptions(): int
    {
        $escalated = 0;
        $hours = ContributionPolicySettings::timingDiffEscalateHours();

        ReconciliationException::query()
            ->open()
            ->where('exception_type', 'timing_difference')
            ->whereNotNull('deferred_until')
            ->where('deferred_until', '<', now()->subHours($hours))
            ->each(function (ReconciliationException $exception) use (&$escalated): void {
                $exception->update([
                    'severity' => 'high',
                    'deferred_until' => null,
                    'sla_deadline' => now()->endOfDay(),
                ]);
                $escalated++;
            });

        return $escalated;
    }
}
