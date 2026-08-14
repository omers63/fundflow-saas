<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use App\Support\AutomationScheduleSettings;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Consolidated Automation → Schedule form sections (cycle clocks, behaviour, jobs, notifications).
 */
final class AutomationScheduleFormSchema
{
    /**
     * @return list<Section>
     */
    public static function sections(): array
    {
        return [
            Section::make(__('Cycle & month boundary'))
                ->description(__('Day and time when contribution cycles turn over, EMI windows close, and monthly reconcile/statements run.'))
                ->columns(2)
                ->schema([
                    TextInput::make('cycle_start_day')
                        ->label(__('Cycle start day'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(28)
                        ->required()
                        ->helperText(__('Day of month when the contribution cycle starts (1–28). Closing the previous cycle and opening the next run automatically on this day at the times below.')),
                    TextInput::make('automation_month_boundary_day')
                        ->label(__('Month-boundary automation day'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(28)
                        ->required()
                        ->helperText(__('Day of month for monthly reconciliation snapshot and statement generation. Defaults to the cycle start day.')),
                    TextInput::make('automation_month_boundary_time')
                        ->label(__('Month-boundary time'))
                        ->required()
                        ->placeholder('00:30')
                        ->helperText(__('24-hour clock time (HH:MM) for monthly reconcile and statements.')),
                    TextInput::make('automation_cycle_close_time')
                        ->label(__('Close collection window time'))
                        ->required()
                        ->placeholder('00:30')
                        ->helperText(__('Runs on the cycle start day to mark unpaid contributions overdue.')),
                    TextInput::make('automation_cycle_init_time')
                        ->label(__('Init contribution cycle time'))
                        ->required()
                        ->placeholder('00:35')
                        ->helperText(__('Runs on the cycle start day to open the new period (usually a few minutes after close).')),
                    TextInput::make('automation_emi_close_day')
                        ->label(__('Close EMI window day'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(28)
                        ->required()
                        ->helperText(__('Day of month to mark unpaid EMIs overdue. Defaults to the cycle start day.')),
                    TextInput::make('automation_emi_close_time')
                        ->label(__('Close EMI window time'))
                        ->required()
                        ->placeholder('00:45')
                        ->helperText(__('24-hour clock time (HH:MM) on the EMI close day.')),
                    TextInput::make('automation_statements_day')
                        ->label(__('Generate statements day'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(28)
                        ->required()
                        ->helperText(__('Day of month for monthly statement generation. Defaults to the month-boundary day.')),
                    TextInput::make('automation_statements_time')
                        ->label(__('Generate statements time'))
                        ->required()
                        ->placeholder('00:30')
                        ->helperText(__('24-hour clock time (HH:MM) for statement generation.')),
                ]),
            Section::make(__('Automation behaviour'))
                ->description(__('Control whether deposits and cash allocations run without manual review.'))
                ->columns(2)
                ->schema([
                    Toggle::make('automation_auto_accept_deposits')
                        ->label(__('Auto-accept deposits'))
                        ->helperText(__('When enabled, member deposit requests are accepted immediately and credited to cash. Admins still receive the new-deposit notification. When disabled, deposits stay pending until an admin accepts them.'))
                        ->default(true),
                    Toggle::make('automation_auto_apply_collections')
                        ->label(__('Auto-apply allocations, contributions, and EMI repayments'))
                        ->helperText(__('When enabled, available cash is allocated automatically (parent→dependent shares, contributions, and EMI repayments) on deposit/credit and on the scheduled apply jobs. When disabled, only explicit admin apply actions run.'))
                        ->default(true),
                ]),
            Section::make(__('Job schedule'))
                ->description(__('Configure when each automation job runs. Due notifications fire on selected days after the cycle opens. Apply jobs run once or twice daily while the cycle is open; late fees and loan delinquency follow each apply automatically. Daily fund and bank jobs use the times below.'))
                ->columns(2)
                ->schema([
                    TextInput::make('automation_contribution_due_notify_days')
                        ->label(__('Contribution due notify days'))
                        ->required()
                        ->helperText(__('Days after cycle open (0 = open day), comma-separated. Example: 0,7,14,21')),
                    TextInput::make('automation_contribution_due_notify_time')
                        ->label(__('Contribution due notify time'))
                        ->required()
                        ->placeholder('09:00')
                        ->helperText(__('24-hour clock time (HH:MM).')),
                    TextInput::make('automation_loan_due_notify_days')
                        ->label(__('Loan due notify days'))
                        ->required()
                        ->helperText(__('Days after cycle open (0 = open day), comma-separated.')),
                    TextInput::make('automation_loan_due_notify_time')
                        ->label(__('Loan due notify time'))
                        ->required()
                        ->placeholder('09:00')
                        ->helperText(__('24-hour clock time (HH:MM).')),
                    TextInput::make('automation_contribution_apply_times')
                        ->label(__('Apply contributions times'))
                        ->required()
                        ->placeholder('06:00')
                        ->helperText(__('One or two HH:MM times per day while the cycle is open (e.g. 06:00 or 06:00,18:00). Late fees run after each apply.')),
                    TextInput::make('automation_loan_apply_times')
                        ->label(__('Apply loan repayments times'))
                        ->required()
                        ->placeholder('06:00')
                        ->helperText(__('One or two HH:MM times per day while the cycle is open. Delinquency check runs after each apply.')),
                    ...self::cadenceFields('master_invariants', __('Assert master invariants'), __('Check that master cash/fund match member sums.')),
                    ...self::cadenceFields('daily_reconcile', __('Daily reconciliation'), __('Ledger audit snapshot.')),
                    ...self::cadenceFields('nightly_reconcile', __('Nightly reconciliation'), __('Master, contributions, EMI, and bank checks.')),
                    ...self::cadenceFields('delinquency_digest', __('Delinquency digest'), __('Admin digest of delinquency status. Review queues under Operations → Delinquency.')),
                    ...self::cadenceFields('fund_status_digest', __('Fund status digest'), __('Admin digest of balances, pending queues, and open operational issues.')),
                    ...self::cadenceFields('bank_auto_match', __('Bank auto-match'), __('Matching of imported bank lines to uncleared postings.')),
                    TextInput::make('automation_late_fees_time')
                        ->label(__('Apply late fees time'))
                        ->required()
                        ->placeholder('06:05')
                        ->helperText(__('Daily late-fee pass (also runs after each Apply contributions when enabled).')),
                    TextInput::make('automation_loan_defaults_time')
                        ->label(__('Loan delinquency check time'))
                        ->required()
                        ->placeholder('06:05')
                        ->helperText(__('Daily delinquency/guarantor maintenance (also runs after each Apply loan repayments when enabled). Review results under Operations → Delinquency.')),
                    TextInput::make('automation_onboarding_greeting_time')
                        ->label(__('Onboarding greeting catch-up time'))
                        ->required()
                        ->placeholder('10:00')
                        ->helperText(__('Used only when scheduled onboarding greeting catch-up is enabled.')),
                    Toggle::make('automation_late_fees_enabled')
                        ->label(__('Enable late fee automation'))
                        ->helperText(__('When off, scheduled and post-apply late fee passes are skipped.'))
                        ->default(true),
                    Toggle::make('automation_loan_defaults_enabled')
                        ->label(__('Enable loan delinquency check automation'))
                        ->helperText(__('When off, scheduled and post-apply delinquency checks are skipped.'))
                        ->default(true),
                    Toggle::make('automation_dispatch_announcements_enabled')
                        ->label(__('Enable scheduled announcement dispatch'))
                        ->helperText(__('When enabled, polls on the interval below for member announcements whose send time has arrived.'))
                        ->live()
                        ->default(true),
                    Select::make('automation_dispatch_announcements_interval_minutes')
                        ->label(__('Announcement dispatch polling interval'))
                        ->options(AutomationScheduleSettings::pollingIntervalOptions())
                        ->required()
                        ->native(false)
                        ->visible(fn (Get $get): bool => (bool) $get('automation_dispatch_announcements_enabled'))
                        ->helperText(__('How often the dispatcher checks for due announcements. The scheduler still wakes every minute; this controls how often the job actually runs.')),
                    Toggle::make('automation_onboarding_greeting_enabled')
                        ->label(__('Enable scheduled onboarding greeting catch-up'))
                        ->helperText(__('When on, catch-up at the time above sends the welcome only to members who have not received it yet. A member is never greeted twice.'))
                        ->default(false),
                ]),
            Section::make(__('Fixed & manual jobs'))
                ->description(__('These catalog jobs are not tenant day/time slots. Use Run now from the job catalog when you need them.'))
                ->schema([
                    Placeholder::make('fixed_jobs_note')
                        ->hiddenLabel()
                        ->content(__('Push loan schedule (grace): manual only (one-time). Ensure queue worker: every minute on the host (system).')),
                ]),
            Section::make(__('Automation notifications'))
                ->description(__('Turn off notifications from scheduled automation. Jobs can still run; only outbound alerts are suppressed. Channel and push-event settings under Communication still apply when notifications are enabled.'))
                ->columns(2)
                ->schema([
                    Toggle::make('automation_notify_contribution_due')
                        ->label(__('Contribution due notifications'))
                        ->helperText(__('Notify members on the configured cycle days.'))
                        ->default(true),
                    Toggle::make('automation_notify_loan_due')
                        ->label(__('Loan due notifications'))
                        ->helperText(__('Notify borrowers of EMI due on the configured cycle days.'))
                        ->default(true),
                    Toggle::make('automation_notify_delinquency_digest')
                        ->label(__('Delinquency digest'))
                        ->helperText(__('Daily admin digest when arrears or overdue EMIs exist.'))
                        ->default(true),
                    Toggle::make('automation_notify_fund_status_digest')
                        ->label(__('Fund status digest'))
                        ->helperText(__('Daily admin summary of fund balances, pending deposits/requests, and open operational issues.'))
                        ->default(true),
                    Toggle::make('automation_notify_reconciliation_digest')
                        ->label(__('Reconciliation digests'))
                        ->helperText(__('Admin alerts for daily, monthly, and nightly reconciliation runs.'))
                        ->default(true),
                    Toggle::make('automation_notify_monthly_statements')
                        ->label(__('Monthly statement notifications'))
                        ->helperText(__('When statement generation runs with notify, send member statement-ready alerts.'))
                        ->default(true),
                    Toggle::make('automation_notify_announcements')
                        ->label(__('Scheduled announcement notifications'))
                        ->helperText(__('When off, due announcements are not dispatched even if dispatch is enabled.'))
                        ->default(true),
                    Toggle::make('automation_notify_onboarding_greeting')
                        ->label(__('Onboarding greeting notifications'))
                        ->helperText(__('Controls scheduled catch-up sends. Catch-up never re-sends to a member who already received the greeting.'))
                        ->default(true),
                ]),
        ];
    }

    /**
     * Returns the 4 cadence controls for a scoped job: frequency select, weekdays multi-select,
     * month-days multi-select, and times text input. Wrapped in a Fieldset for visual grouping.
     *
     * @return list<Fieldset>
     */
    private static function cadenceFields(string $job, string $label, string $helperText): array
    {
        $freqKey = "automation_{$job}_frequency";
        $weekdaysKey = "automation_{$job}_weekdays";
        $monthDaysKey = "automation_{$job}_month_days";
        $timesKey = "automation_{$job}_times";

        return [
            Fieldset::make($label)
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Select::make($freqKey)
                        ->label(__('Frequency'))
                        ->options([
                            'daily' => __('Daily'),
                            'weekly' => __('Weekly'),
                            'monthly' => __('Monthly'),
                            'disabled' => __('Disabled'),
                        ])
                        ->required()
                        ->native(false)
                        ->live()
                        ->helperText($helperText),
                    TextInput::make($timesKey)
                        ->label(__('Time(s)'))
                        ->required()
                        ->placeholder('06:00')
                        ->helperText(__('One or more HH:MM times, comma-separated (e.g. 06:00 or 06:00,18:00).'))
                        ->visible(fn (Get $get): bool => $get($freqKey) !== 'disabled'),
                    Select::make($weekdaysKey)
                        ->label(__('Weekdays'))
                        ->options([
                            1 => __('Monday'),
                            2 => __('Tuesday'),
                            3 => __('Wednesday'),
                            4 => __('Thursday'),
                            5 => __('Friday'),
                            6 => __('Saturday'),
                            7 => __('Sunday'),
                        ])
                        ->multiple()
                        ->native(false)
                        ->required()
                        ->visible(fn (Get $get): bool => $get($freqKey) === 'weekly')
                        ->helperText(__('Which days of the week this job should run.')),
                    Select::make($monthDaysKey)
                        ->label(__('Day(s) of month'))
                        ->options(array_combine(range(1, 28), range(1, 28)))
                        ->multiple()
                        ->native(false)
                        ->required()
                        ->visible(fn (Get $get): bool => $get($freqKey) === 'monthly')
                        ->helperText(__('Which days of the month (1–28) this job should run.')),
                ]),
        ];
    }
}
