<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Setting;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Support\ContributionAmountSettings;
use App\Support\ContributionCollectionStatus;
use App\Support\InstallmentCollectionStatus;
use App\Support\LegacyImportedContribution;
use App\Support\LegacyImportedLoan;

/**
 * Visual treatment for contribution/repayment rows settled after their deadline, and the one place
 * that derives the unified payment state (label, colour, hint) of an EMI or a contribution.
 *
 * States, in precedence order: waived, paid_early, paid_guarantor, paid_late, paid_late_legacy, paid,
 * partial, overdue, pending, failed. Mirrors the Nest `paymentState()` in packages/shared.
 */
final class LateSettledArrearsTableStyling
{
    /** @var string Tailwind classes applied to late-settled table rows. */
    public const LATE_ROW_CLASSES = 'bg-rose-50/90 dark:bg-rose-950/30 [&_td]:text-rose-950 dark:[&_td]:text-rose-100';

    /** Why a row is waived (stored in `waive_reason`). */
    public const WAIVE_REASON_CUTOFF = 'cutoff';

    public const WAIVE_REASON_ADMIN_CLEARANCE = 'admin_clearance';

    public const WAIVE_REASON_THRESHOLD = 'threshold';

    public const WAIVE_REASON_EARLY_SKIP = 'early_skip';

    public const WAIVE_REASON_FISCAL_CLOSE = 'fiscal_close';

    public static function contributionWasSettledLate(Contribution $contribution): bool
    {
        if (! $contribution->is_late) {
            return false;
        }

        if ($contribution->status === 'posted') {
            return true;
        }

        return $contribution->collection_status === ContributionCollectionStatus::COLLECTED;
    }

    public static function installmentWasSettledLate(LoanInstallment $installment): bool
    {
        return $installment->status === 'paid' && (bool) $installment->is_late;
    }

    /**
     * Legacy-migrated contribution posted after its cycle window but never flagged late
     * (legacy rows carry no late flag or fee).
     */
    public static function contributionWasSettledLegacyLate(Contribution $contribution): bool
    {
        if ($contribution->status !== 'posted' || $contribution->is_late || $contribution->posted_at === null) {
            return false;
        }

        $period = $contribution->period;

        if ($period === null || ! LegacyImportedContribution::isContribution($contribution)) {
            return false;
        }

        $dueEnd = app(ContributionCycleService::class)->cycleDueEndAt((int) $period->month, (int) $period->year);

        return $contribution->posted_at->greaterThan($dueEnd);
    }

    /**
     * Legacy-migrated installment paid after its due date but never flagged late.
     */
    public static function installmentWasSettledLegacyLate(LoanInstallment $installment): bool
    {
        if ($installment->status !== 'paid' || $installment->is_late || $installment->paid_at === null || $installment->due_date === null) {
            return false;
        }

        if (! LegacyImportedLoan::isLoan((int) $installment->loan_id)) {
            return false;
        }

        return $installment->paid_at->toDateString() > $installment->due_date->toDateString();
    }

    /**
     * A waived EMI with the EMI amount collected is a cycle paid in advance and skipped
     * (early settlement, formerly "Skipped"); rows with no waive reason fall back to that rule.
     */
    public static function installmentWasPaidEarly(LoanInstallment $installment): bool
    {
        if ($installment->status !== 'waived') {
            return false;
        }

        $reason = (string) ($installment->waive_reason ?? '');

        return $reason === self::WAIVE_REASON_EARLY_SKIP
            || ($reason === '' && (float) ($installment->amount_collected ?? 0) > 0.00001);
    }

    /**
     * @return array{code: string, label: string, color: string, hint: ?string}
     */
    public static function contributionPaymentState(Contribution $contribution): array
    {
        if ($contribution->status === 'waived') {
            return self::state('waived', __('Waived'), 'info', self::waiveHint($contribution->waive_reason));
        }

        if ($contribution->status === 'posted') {
            if ($contribution->is_late) {
                return self::state('paid_late', __('Paid (late)'), 'danger', self::eligibilityHint());
            }

            if (self::contributionWasSettledLegacyLate($contribution)) {
                return self::state('paid_late_legacy', __('Paid (late, legacy)'), 'orange', self::legacyLateHint());
            }

            return self::state('paid', __('Paid'), 'success');
        }

        if ($contribution->status === 'failed') {
            return self::state('failed', __('Failed'), 'danger', __('Not enough cash on the collection attempt'));
        }

        if (self::contributionWasSettledLate($contribution)) {
            return self::state('paid_late', __('Paid (late)'), 'danger', self::eligibilityHint());
        }

        if (self::contributionIsPartiallyPaid($contribution)) {
            return self::state('partial', __('Paid (partial)'), 'warning', self::partialHint());
        }

        $collectionStatus = (string) $contribution->collection_status;

        if ($collectionStatus === ContributionCollectionStatus::OVERDUE || str_starts_with($collectionStatus, 'late_')) {
            return self::state('overdue', __('Overdue'), 'danger');
        }

        return self::state('pending', __('Pending'), 'warning');
    }

    /**
     * @return array{code: string, label: string, color: string, hint: ?string}
     */
    public static function installmentPaymentState(LoanInstallment $installment): array
    {
        if ($installment->status === 'waived') {
            if (self::installmentWasPaidEarly($installment)) {
                return self::state('paid_early', __('Paid (early)'), 'info', self::waiveHint(self::WAIVE_REASON_EARLY_SKIP));
            }

            return self::state('waived', __('Waived'), 'info', self::waiveHint($installment->waive_reason));
        }

        if ($installment->status === 'paid') {
            if ($installment->paid_by_guarantor) {
                return self::state('paid_guarantor', __('Paid (guarantor)'), 'violet', __('Covered from the guarantor fund'));
            }

            if (self::installmentWasSettledLate($installment)) {
                return self::state('paid_late', __('Paid (late)'), 'danger', self::eligibilityHint());
            }

            if (self::installmentWasSettledLegacyLate($installment)) {
                return self::state('paid_late_legacy', __('Paid (late, legacy)'), 'orange', self::legacyLateHint());
            }

            return self::state('paid', __('Paid'), 'success');
        }

        if (self::installmentIsPartiallyPaid($installment)) {
            return self::state('partial', __('Paid (partial)'), 'warning', self::partialHint());
        }

        $collectionStatus = (string) $installment->collection_status;

        if ($installment->status === 'overdue'
            || $collectionStatus === InstallmentCollectionStatus::OVERDUE
            || str_starts_with($collectionStatus, 'late_')) {
            return self::state('overdue', __('Overdue'), 'danger');
        }

        return self::state('pending', __('Pending'), 'warning');
    }

    public static function contributionStatusLabel(Contribution $contribution): string
    {
        return self::contributionPaymentState($contribution)['label'];
    }

    public static function contributionStatusColor(Contribution $contribution): string
    {
        return self::contributionPaymentState($contribution)['color'];
    }

    public static function contributionIsPartiallyPaid(Contribution $contribution): bool
    {
        if ($contribution->status === 'posted') {
            return false;
        }

        if ($contribution->collection_status === ContributionCollectionStatus::PARTIALLY_PENDING) {
            return true;
        }

        return $contribution->status === 'pending'
            && (float) ($contribution->amount_collected ?? 0) > 0.00001;
    }

    public static function installmentStatusLabel(LoanInstallment $installment): string
    {
        return self::installmentPaymentState($installment)['label'];
    }

    public static function installmentStatusColor(LoanInstallment $installment): string
    {
        return self::installmentPaymentState($installment)['color'];
    }

    public static function installmentIsPartiallyPaid(LoanInstallment $installment): bool
    {
        if ($installment->status === 'paid') {
            return false;
        }

        if ($installment->collection_status === InstallmentCollectionStatus::PARTIALLY_PENDING) {
            return true;
        }

        return in_array($installment->status, ['pending', 'overdue'], true)
            && (float) ($installment->amount_collected ?? 0) > 0.00001;
    }

    public static function eligibilityHint(): string
    {
        return __('Settled after the deadline; counts toward late payment history and may affect loan eligibility.');
    }

    public static function legacyLateHint(): string
    {
        return __('Settled after the deadline before migration to this system; not counted in late payment history and carries no late fee.');
    }

    public static function partialHint(): string
    {
        return __('Part of the amount is collected; the rest is outstanding');
    }

    public static function waiveHint(?string $reason): ?string
    {
        return match ($reason) {
            self::WAIVE_REASON_CUTOFF => __('Before arrears cutoff; not collected'),
            self::WAIVE_REASON_ADMIN_CLEARANCE => __('Cleared by an administrator; no cash movement'),
            self::WAIVE_REASON_THRESHOLD => __('Small balance written off under the waiver threshold'),
            self::WAIVE_REASON_EARLY_SKIP => __('Paid in advance; this cycle was skipped'),
            self::WAIVE_REASON_FISCAL_CLOSE => __('Waived at fiscal close'),
            default => null,
        };
    }

    public static function contributionRecordClasses(Contribution $contribution): ?string
    {
        return self::contributionWasSettledLate($contribution) ? self::LATE_ROW_CLASSES : null;
    }

    public static function installmentRecordClasses(LoanInstallment $installment): ?string
    {
        return self::installmentWasSettledLate($installment) ? self::LATE_ROW_CLASSES : null;
    }

    /** @var array<int, array<string, int>> loan id => cycle key => paid EMI count */
    private static array $paidInCycleMemo = [];

    public static function flushPaymentFlagMemo(): void
    {
        self::$paidInCycleMemo = [];
    }

    /** Contribution cycle a date falls in ("YYYY-MM"): cycle runs from the start day to the day before the next start day. */
    public static function cycleKeyOf(\Carbon\CarbonInterface $at): string
    {
        $startDay = Setting::contributionCycleStartDay();

        return $at->copy()->startOfDay()->subDays($startDay - 1)->format('Y-m');
    }

    private static function paidInstallmentsInCycle(LoanInstallment $installment): int
    {
        if ($installment->status !== 'paid' || $installment->paid_at === null) {
            return 0;
        }

        $loanId = (int) $installment->loan_id;

        if (! isset(self::$paidInCycleMemo[$loanId])) {
            $counts = [];

            LoanInstallment::query()
                ->where('loan_id', $loanId)
                ->where('status', 'paid')
                ->whereNotNull('paid_at')
                ->pluck('paid_at')
                ->each(function ($paidAt) use (&$counts): void {
                    $key = self::cycleKeyOf(\Illuminate\Support\Carbon::parse($paidAt));
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                });

            self::$paidInCycleMemo[$loanId] = $counts;
        }

        return self::$paidInCycleMemo[$loanId][self::cycleKeyOf($installment->paid_at)] ?? 0;
    }

    private static function money(float $n): string
    {
        return number_format($n, 2, '.', ',');
    }

    /**
     * Highlights for a contribution: above the configured maximum amount.
     *
     * @return array<int, array{code: string, label: string, color: string, hint: string}>
     */
    public static function contributionFlags(Contribution $contribution): array
    {
        $max = (float) ContributionAmountSettings::maxAmount();
        $amount = (float) $contribution->amount;

        if ($max > 0.00001 && $amount > $max + 0.005) {
            $hint = __('Contribution :amount is above the maximum :max', ['amount' => self::money($amount), 'max' => self::money($max)])
                .(LegacyImportedContribution::isContribution($contribution) ? ' '.__('(legacy-migrated payment)') : '');

            return [['code' => 'above_limit', 'label' => __('Above limit'), 'color' => 'warning', 'hint' => $hint]];
        }

        return [];
    }

    /**
     * Highlights for an installment: above limit (several EMIs in one cycle, over-collected) and early settlement.
     *
     * @return array<int, array{code: string, label: string, color: string, hint: string}>
     */
    public static function installmentFlags(LoanInstallment $installment): array
    {
        $flags = [];
        $reasons = [];
        $count = self::paidInstallmentsInCycle($installment);

        if ($count > 1) {
            $reasons[] = __(':count EMIs of this loan were paid in the same cycle', ['count' => $count]);
        }

        $amount = (float) $installment->amount;
        $collected = (float) ($installment->amount_collected ?? 0);

        if ($installment->status === 'paid' && $amount > 0.00001 && $collected > $amount + 0.01) {
            $reasons[] = __('Collected :collected is above the EMI amount :amount', ['collected' => self::money($collected), 'amount' => self::money($amount)]);
        }

        if ($reasons !== []) {
            $legacy = LegacyImportedLoan::isLoan((int) $installment->loan_id) ? ' '.__('(legacy-migrated payment)') : '';
            $flags[] = ['code' => 'above_limit', 'label' => __('Above limit'), 'color' => 'warning', 'hint' => implode('; ', $reasons).$legacy];
        }

        if ($installment->settled_via === 'early_full') {
            $flags[] = ['code' => 'early_settlement_full', 'label' => __('Early settlement (full)'), 'color' => 'info', 'hint' => __('Settled as part of a full early settlement of the loan')];
        } elseif ($installment->settled_via === 'early_partial' || $installment->waive_reason === self::WAIVE_REASON_EARLY_SKIP) {
            $flags[] = ['code' => 'early_settlement_partial', 'label' => __('Early settlement (partial)'), 'color' => 'info', 'hint' => __('Settled as part of a partial early settlement of the loan')];
        }

        return $flags;
    }

    /**
     * Late-fee chip (separate from the main status): due, part paid, paid; null when the row has no fee.
     *
     * @return array{code: string, label: string, color: string, hint: string}|null
     */
    public static function lateFeeChip(Contribution|LoanInstallment $row): ?array
    {
        $assessed = (float) ($row->late_fee_amount ?? 0);

        if ($assessed <= 0.00001) {
            return null;
        }

        $accounting = app(AccountingService::class);
        $collected = $row instanceof Contribution
            ? (float) $accounting->contributionLateFeeCollectedAmount($row)
            : (float) $accounting->installmentLateFeeCollectedAmount($row);

        if ($collected <= 0.00001) {
            return ['code' => 'assessed', 'label' => __('Late fee due'), 'color' => 'warning', 'hint' => __('Late fee')];
        }

        if ($collected + 0.00001 < $assessed) {
            return ['code' => 'part_collected', 'label' => __('Late fee part paid'), 'color' => 'warning', 'hint' => __('Late fee')];
        }

        return ['code' => 'collected', 'label' => __('Late fee paid'), 'color' => 'success', 'hint' => __('Late fee')];
    }

    /**
     * Every extra chip for a row: late fee, above limit, early settlement.
     *
     * @return array<int, array{code: string, label: string, color: string, hint: string}>
     */
    public static function paymentChips(Contribution|LoanInstallment $row): array
    {
        $fee = self::lateFeeChip($row);
        $flags = $row instanceof Contribution ? self::contributionFlags($row) : self::installmentFlags($row);

        return array_values(array_filter([$fee, ...$flags]));
    }

    /**
     * @return array{code: string, label: string, color: string, hint: ?string}
     */
    private static function state(string $code, string $label, string $color, ?string $hint = null): array
    {
        return ['code' => $code, 'label' => $label, 'color' => $color, 'hint' => $hint];
    }
}
