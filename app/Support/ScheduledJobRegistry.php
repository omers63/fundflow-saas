<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Tenant schedulable commands exposed in System → Jobs.
 *
 * @phpstan-type JobDefinition array{
 *     key: string,
 *     command: string,
 *     label: string,
 *     description: string,
 *     schedule: string,
 *     category: string,
 *     halt_sensitive: bool
 * }
 */
final class ScheduledJobRegistry
{
    /**
     * @return list<JobDefinition>
     */
    public static function all(): array
    {
        return [
            self::job('fund:assert-master-invariants', __('Assert master invariants'), __('Verify master cash/fund equal member sums'), __('Daily at 06:00'), 'fund', false),
            self::job('fund:nightly-reconciliation', __('Nightly reconciliation'), __('Master, contributions, EMI, and bank checks'), __('Daily at 06:30'), 'reconciliation', false),
            self::job('contributions:init-cycle', __('Init contribution cycle'), __('Create pending rows for open period'), __('Monthly on 1st at 08:00'), 'contributions', true),
            self::job('contributions:notify', __('Contribution due notifications'), __('Notify members of open period'), __('Monthly on 1st at 09:00'), 'contributions', false),
            self::job('contributions:apply', __('Apply contributions'), __('Debit member cash for open period'), __('Monthly on 5th at 09:00'), 'contributions', true),
            self::job('contributions:close-window', __('Close collection window'), __('Mark unpaid as overdue'), __('Monthly on 6th at 00:30'), 'contributions', true),
            self::job('loans:close-emi-window', __('Close EMI collection window'), __('Mark unpaid installments overdue'), __('Monthly on 6th at 00:45'), 'loans', true),
            self::job('contributions:apply-late-fees', __('Apply late fees'), __('Contribution and EMI late fee tiers'), __('Daily at 07:15'), 'contributions', true),
            self::job('bank:auto-match', __('Bank auto-match'), __('Match imports to uncleared fund postings'), __('Daily at 08:00'), 'bank', true),
            self::job('statements:generate --notify', __('Generate statements'), __('Monthly statements with notifications'), __('Monthly on 3rd at 08:00'), 'statements', false),
            self::job('loans:send-due-notifications', __('Loan due notifications'), __('Notify borrowers of EMI due'), __('Monthly on 1st at 08:00'), 'loans', false),
            self::job('loans:apply-repayments', __('Apply loan repayments'), __('Batch EMI collection for period'), __('Monthly on 6th at 06:00'), 'loans', true),
            self::job('loans:check-defaults', __('Loan delinquency check'), __('Overdue, delinquency, guarantor defaults, auto-transfer'), __('Daily at 07:00'), 'loans', false),
            self::job('delinquency:send-digest', __('Delinquency digest'), __('Email/database digest to admins'), __('Daily at 07:30'), 'loans', false),
        ];
    }

    /**
     * @return JobDefinition|null
     */
    public static function find(string $key): ?array
    {
        foreach (self::all() as $job) {
            if ($job['key'] === $key) {
                return $job;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function commandNames(): array
    {
        return array_map(
            fn (array $job): string => explode(' ', $job['command'])[0],
            self::all(),
        );
    }

    /**
     * @return JobDefinition
     */
    private static function job(
        string $command,
        string $label,
        string $description,
        string $schedule,
        string $category,
        bool $haltSensitive,
    ): array {
        return [
            'key' => $command,
            'command' => $command,
            'label' => $label,
            'description' => $description,
            'schedule' => $schedule,
            'category' => $category,
            'halt_sensitive' => $haltSensitive,
        ];
    }
}
