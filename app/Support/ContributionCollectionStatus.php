<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Member contribution cycle states per collection_cycle_workflow.md.
 */
final class ContributionCollectionStatus
{
    public const PENDING = 'pending';

    public const PARTIALLY_PENDING = 'partially_pending';

    public const COLLECTED = 'collected';

    public const OVERDUE = 'overdue';

    public const LATE_T1 = 'late_t1';

    public const LATE_T2 = 'late_t2';

    public const LATE_T3 = 'late_t3';

    public const LATE_T4 = 'late_t4';

    public const SETTLING = 'settling';

    /** @return list<string> */
    public static function openCollectionStates(): array
    {
        return [
            self::PENDING,
            self::PARTIALLY_PENDING,
            self::OVERDUE,
            self::LATE_T1,
            self::LATE_T2,
            self::LATE_T3,
            self::LATE_T4,
            self::SETTLING,
        ];
    }

    /** @return list<string> */
    public static function lateStates(): array
    {
        return [self::LATE_T1, self::LATE_T2, self::LATE_T3, self::LATE_T4];
    }

    public static function tierForDays(int $daysOverdue): ?int
    {
        if ($daysOverdue <= ContributionPolicySettings::lateFeeReminderDays()) {
            return null;
        }

        if ($daysOverdue >= ContributionPolicySettings::lateFeeTier4Day()) {
            return 4;
        }

        if ($daysOverdue >= ContributionPolicySettings::lateFeeTier3Day()) {
            return 3;
        }

        if ($daysOverdue >= ContributionPolicySettings::lateFeeTier2Day()) {
            return 2;
        }

        if ($daysOverdue > ContributionPolicySettings::lateFeeReminderDays()) {
            return 1;
        }

        return null;
    }

    public static function labelForTier(?int $tier): string
    {
        return match ($tier) {
            1 => self::LATE_T1,
            2 => self::LATE_T2,
            3 => self::LATE_T3,
            4 => self::LATE_T4,
            default => self::OVERDUE,
        };
    }
}
