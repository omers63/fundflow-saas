<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputInterface;

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
            self::job('fund:reconcile --daily', __('Daily reconciliation snapshot'), __('Ledger audit report stored for history'), __('Daily at 06:20'), 'reconciliation', false),
            self::job('fund:nightly-reconciliation', __('Nightly reconciliation'), __('Master, contributions, EMI, and bank checks'), __('Daily at 06:30'), 'reconciliation', false),
            self::job('fund:reconcile --monthly', __('Monthly reconciliation snapshot'), __('Previous month period metrics plus ledger audit'), AutomationScheduleSettings::monthBoundaryScheduleLabel(), 'reconciliation', false),
            self::job('contributions:close-window', __('Close collection window'), __('Mark unpaid as overdue for the period that just ended'), self::contributionCycleCloseSchedule(), 'contributions', true),
            self::job('contributions:init-cycle', __('Init contribution cycle'), __('Create pending rows for the newly opened period (runs immediately after close)'), self::contributionCycleInitSchedule(), 'contributions', true),
            self::job('contributions:notify', __('Contribution due notifications'), __('Notify members of open period on configured cycle days'), AutomationScheduleSettings::contributionDueNotifyScheduleLabel(), 'contributions', false),
            self::job('contributions:apply', __('Apply contributions'), __('Debit member cash for open period; then apply late fees'), AutomationScheduleSettings::contributionApplyScheduleLabel(), 'contributions', true),
            self::job('loans:close-emi-window', __('Close EMI collection window'), __('Mark unpaid installments overdue'), __('Monthly on 6th at 00:45'), 'loans', true),
            self::job('contributions:apply-late-fees', __('Apply late fees'), __('Contribution and EMI late fee tiers (also runs after each Apply contributions)'), __('After each Apply contributions'), 'contributions', true),
            self::job('bank:auto-match', __('Bank auto-match'), __('Match imports to uncleared fund postings'), __('Daily at 08:00'), 'bank', true),
            self::job('statements:generate --notify', __('Generate statements'), __('Monthly statements with notifications'), AutomationScheduleSettings::monthBoundaryScheduleLabel(), 'statements', false),
            self::job('loans:send-due-notifications', __('Loan due notifications'), __('Notify borrowers of EMI due on configured cycle days'), AutomationScheduleSettings::loanDueNotifyScheduleLabel(), 'loans', false),
            self::job('loans:apply-repayments', __('Apply loan repayments'), __('Batch EMI collection; then loan delinquency check'), AutomationScheduleSettings::loanApplyScheduleLabel(), 'loans', true),
            self::job('loans:check-defaults', __('Loan delinquency check'), __('Overdue, delinquency, guarantor defaults, auto-transfer (also runs after each Apply loan repayments)'), __('After each Apply loan repayments'), 'loans', false),
            self::job('delinquency:send-digest', __('Delinquency digest'), __('Email/database digest to admins'), __('Daily at 07:30'), 'loans', false),
            self::job('announcements:dispatch-scheduled', __('Dispatch scheduled announcements'), __('Send bulk member announcements when their scheduled time arrives'), __('Every minute'), 'messaging', false),
            self::job('members:send-onboarding-greeting', __('Send onboarding greeting'), __('Email active members the welcome / PWA onboarding guide (use after legacy migration or as a catch-up)'), __('Manual'), 'messaging', false),
            self::job('queue:ensure-worker', __('Ensure queue worker'), __('Restart and start queue:work when no worker process is detected'), __('Every minute'), 'system', false),
        ];
    }

    public static function contributionCycleCloseSchedule(): string
    {
        return __('Daily check at 00:30 — runs on cycle start day (:day)', [
            'day' => self::contributionCycleStartDayLabel(),
        ]);
    }

    public static function contributionCycleInitSchedule(): string
    {
        return __('Daily check at 00:35 — runs on cycle start day (:day), right after close', [
            'day' => self::contributionCycleStartDayLabel(),
        ]);
    }

    private static function contributionCycleStartDayLabel(): int
    {
        if (! function_exists('tenancy') || ! tenancy()->initialized) {
            return 6;
        }

        return Setting::contributionCycleStartDay();
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
     * Resolve a registry definition for a running Artisan command (including option flags).
     *
     * @return JobDefinition|null
     */
    public static function findForCommand(Command $command): ?array
    {
        $name = $command->getName();

        if ($name === null || $name === '') {
            return null;
        }

        return self::findForCommandName($name, function (string $flag) use ($command): bool {
            if (! $command->getDefinition()->hasOption($flag)) {
                return false;
            }

            $value = $command->option($flag);

            return self::optionValueIsPresent($value);
        });
    }

    /**
     * Resolve a registry definition from a command name + Symfony input.
     *
     * @return JobDefinition|null
     */
    public static function findForInput(string $commandName, InputInterface $input): ?array
    {
        return self::findForCommandName($commandName, function (string $flag) use ($input): bool {
            if ($input->hasParameterOption('--'.$flag, true)) {
                return true;
            }

            return $input->hasParameterOption('--'.$flag.'=', true);
        });
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
     * @param  callable(string): bool  $optionIsSet
     * @return JobDefinition|null
     */
    private static function findForCommandName(string $commandName, callable $optionIsSet): ?array
    {
        $candidates = array_values(array_filter(
            self::all(),
            fn (array $definition): bool => explode(' ', $definition['command'])[0] === $commandName,
        ));

        if ($candidates === []) {
            return null;
        }

        if (count($candidates) === 1) {
            return $candidates[0];
        }

        $best = null;
        $bestScore = -1;

        foreach ($candidates as $definition) {
            $flags = self::optionFlagsFromCommandString($definition['command']);
            $score = 0;
            $matches = true;

            foreach ($flags as $flag) {
                if (! $optionIsSet($flag)) {
                    $matches = false;
                    break;
                }

                $score++;
            }

            if ($matches && $score > $bestScore) {
                $best = $definition;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * @return list<string>
     */
    private static function optionFlagsFromCommandString(string $command): array
    {
        $parts = preg_split('/\s+/', trim($command)) ?: [];
        array_shift($parts);

        $flags = [];

        foreach ($parts as $part) {
            if (! str_starts_with($part, '--')) {
                continue;
            }

            $flag = substr($part, 2);
            $eqPos = strpos($flag, '=');

            if ($eqPos !== false) {
                $flag = substr($flag, 0, $eqPos);
            }

            if ($flag !== '') {
                $flags[] = $flag;
            }
        }

        return $flags;
    }

    private static function optionValueIsPresent(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return $value !== null && $value !== '';
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
