<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\LoanInstallment;
use App\Support\ContributionCollectionStatus;
use App\Support\InstallmentCollectionStatus;

/**
 * Visual treatment for contribution/repayment rows settled after their deadline.
 */
final class LateSettledArrearsTableStyling
{
    /** @var string Tailwind classes applied to late-settled table rows. */
    public const LATE_ROW_CLASSES = 'bg-rose-50/90 dark:bg-rose-950/30 [&_td]:text-rose-950 dark:[&_td]:text-rose-100';

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

    public static function contributionStatusLabel(Contribution $contribution): string
    {
        if (self::contributionIsPartiallyPaid($contribution)) {
            return __('Partially paid');
        }

        if (self::contributionWasSettledLate($contribution)) {
            return __('Posted (late)');
        }

        return match ($contribution->status) {
            'pending' => __('Pending'),
            'posted' => __('Posted'),
            'failed' => __('Failed'),
            'waived' => __('Waived'),
            default => ucfirst((string) $contribution->status),
        };
    }

    public static function contributionStatusColor(Contribution $contribution): string
    {
        if (self::contributionIsPartiallyPaid($contribution)) {
            return 'warning';
        }

        if (self::contributionWasSettledLate($contribution)) {
            return 'danger';
        }

        return match ($contribution->status) {
            'pending' => 'warning',
            'posted' => 'success',
            'failed' => 'danger',
            'waived' => 'info',
            default => 'gray',
        };
    }

    public static function contributionIsPartiallyPaid(Contribution $contribution): bool
    {
        if ($contribution->collection_status === ContributionCollectionStatus::PARTIALLY_PENDING) {
            return true;
        }

        return $contribution->status === 'pending'
            && (float) ($contribution->amount_collected ?? 0) > 0.00001;
    }

    public static function installmentStatusLabel(LoanInstallment $installment): string
    {
        if (self::installmentIsPartiallyPaid($installment)) {
            return __('Partially paid');
        }

        if ($installment->status === 'paid' && $installment->paid_by_guarantor) {
            return __('Guarantor paid');
        }

        if (self::installmentWasSettledLate($installment)) {
            return __('Paid (late)');
        }

        return match ($installment->status) {
            'pending' => __('Pending'),
            'paid' => __('Paid'),
            'overdue' => __('Overdue'),
            'waived' => $installment->amount_collected > 0 ? __('Skipped') : __('Waived'),
            default => ucfirst((string) $installment->status),
        };
    }

    public static function installmentStatusColor(LoanInstallment $installment): string
    {
        if (self::installmentIsPartiallyPaid($installment)) {
            return 'warning';
        }

        if ($installment->status === 'paid' && $installment->paid_by_guarantor) {
            return 'warning';
        }

        if (self::installmentWasSettledLate($installment)) {
            return 'danger';
        }

        return match ($installment->status) {
            'paid' => 'success',
            'overdue' => 'danger',
            'waived' => 'info',
            default => 'warning',
        };
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

    public static function contributionRecordClasses(Contribution $contribution): ?string
    {
        return self::contributionWasSettledLate($contribution) ? self::LATE_ROW_CLASSES : null;
    }

    public static function installmentRecordClasses(LoanInstallment $installment): ?string
    {
        return self::installmentWasSettledLate($installment) ? self::LATE_ROW_CLASSES : null;
    }
}
