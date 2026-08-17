<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Concerns;

use App\Filament\Support\BusinessDayWindowRollbackHeaderAction;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\SystemJobRun;
use App\Services\Loans\LoanManualScheduleGracePushService;
use App\Services\SystemJobRunnerService;
use App\Support\AutomationSchedulerGate;
use App\Support\ScheduledJobRegistry;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;

trait InteractsWithJobsTable
{
    #[Url(as: 'jobsTab')]
    public string $jobsTab = 'status';

    public function setJobsTab(string $tab): void
    {
        if (! in_array($tab, ['status', 'schedule', 'catalog', 'history'], true)) {
            return;
        }

        if ($this->jobsTab === $tab) {
            return;
        }

        $this->jobsTab = $tab;
        $this->tableSort = null;

        if ($tab === 'schedule' && method_exists($this, 'fillAutomationScheduleForm')) {
            $this->fillAutomationScheduleForm();
        }

        if (method_exists($this, 'reconfigureTableForSideTab')) {
            $this->reconfigureTableForSideTab();
        } else {
            $this->resetJobsTableColumns();
        }

        if ($tab !== 'schedule') {
            $this->resetTable();
        }
    }

    protected function resetJobsTableColumns(): void
    {
        $this->tableColumns = [];
        $this->cachedDefaultTableColumnState = null;
    }

    protected function configureJobsTable(Table $table): Table
    {
        if ($this->jobsTab === 'history') {
            return $this->configureJobsHistoryTable($table);
        }

        if ($this->jobsTab === 'catalog') {
            return $this->configureJobsCatalogTable($table);
        }

        // Status + schedule tabs use custom views, not the Filament table.
        return $table
            ->query(SystemJobRun::query()->whereRaw('1 = 0'))
            ->paginated(false);
    }

    protected function jobsAdvancedUi(): bool
    {
        return property_exists($this, 'advancedUi') && $this->advancedUi;
    }

    protected function configureJobsCatalogTable(Table $table): Table
    {
        return TableGrouping::apply($table
            ->records(fn (): Collection => app(SystemJobRunnerService::class)->catalogRecords())
            ->columnManager(false)
            ->persistColumnsInSession(false)
            ->heading(__('Scheduled jobs'))
            ->filters([
                SelectFilter::make('category')
                    ->options(fn (): array => collect(app(SystemJobRunnerService::class)->catalogRecords())
                        ->pluck('category', 'category')
                        ->unique()
                        ->sort()
                        ->all()),
            ])
            ->columns([
                TextColumn::make('job_label')
                    ->label(__('Job'))
                    ->wrap()
                    ->toggleable(false)
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('category')
                    ->badge()
                    ->toggleable(false)
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('schedule')
                    ->label(__('Schedule'))
                    ->wrap()
                    ->toggleable(false)
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('last_status')
                    ->label(__('Last run'))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        SystemJobRun::STATUS_SUCCESS => 'success',
                        SystemJobRun::STATUS_FAILED => 'danger',
                        SystemJobRun::STATUS_RUNNING => 'warning',
                        default => 'gray',
                    })
                    ->placeholder(__('Never'))
                    ->toggleable(false)
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('last_started_at')
                    ->label(__('Last started'))
                    ->dateTime()
                    ->placeholder(__('—'))
                    ->toggleable(false)
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('last_duration_ms')
                    ->label(__('Duration'))
                    ->formatStateUsing(fn (mixed $state): string => filled($state) ? ((int) $state).' ms' : '—')
                    ->alignEnd()
                    ->toggleable(false)
                    ->searchable(false)
                    ->sortable(false),
            ])
            ->recordActions(TableRecordActionGroups::wrap([
                Action::make('run')
                    ->label(__('Run now'))
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record): string => __('Run :job', ['job' => $record['job_label']]))
                    ->fillForm(fn (array $record): array => $record['key'] === 'loans:push-schedule-grace'
                        ? [
                            'loan' => null,
                            'cycles' => 1,
                            'dry_run' => false,
                        ]
                        : [])
                    ->schema(fn (array $record): array => $record['key'] === 'loans:push-schedule-grace'
                        ? [
                            TextInput::make('loan')
                                ->label(__('Loan #'))
                                ->numeric()
                                ->helperText(__('Leave blank to shift all eligible loans with no beginning grace and exactly one overdue cycle. Enter a loan id to push that loan’s unpaid schedule.')),
                            Select::make('cycles')
                                ->label(__('Cycles to push'))
                                ->options(collect(range(
                                    LoanManualScheduleGracePushService::MIN_CYCLES,
                                    LoanManualScheduleGracePushService::MAX_CYCLES,
                                ))->mapWithKeys(fn (int $n): array => [
                                    $n => trans_choice(':count cycle|:count cycles', $n, ['count' => $n]),
                                ])->all())
                                ->required()
                                ->helperText(__('Unpaid EMIs move forward by this many cycles. The previous first repayment cycle becomes grace-exempt (e.g. Jan → Feb, Jan marked grace).')),
                            Toggle::make('dry_run')
                                ->label(__('Dry run (preview only)'))
                                ->helperText(__('Preview without changing schedules.')),
                        ]
                        : [])
                    ->action(function (array $record, array $data): void {
                        $extra = [];

                        if ($record['key'] === 'loans:push-schedule-grace') {
                            if (filled($data['loan'] ?? null)) {
                                $extra['loan'] = (int) $data['loan'];
                            }

                            $extra['cycles'] = (int) ($data['cycles'] ?? 1);

                            if (! empty($data['dry_run'])) {
                                $extra['dry-run'] = true;
                            }
                        }

                        try {
                            $result = app(SystemJobRunnerService::class)->run(
                                $record['key'],
                                extraParameters: $extra,
                            );
                        } catch (\InvalidArgumentException $exception) {
                            Notification::make()->title(__('Cannot run job'))->body($exception->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title($result['exit_code'] === 0 ? __('Job completed') : __('Job failed'))
                            ->body(__('Exit code: :code', ['code' => $result['exit_code']]))
                            ->color($result['exit_code'] === 0 ? 'success' : 'danger')
                            ->send();

                        $this->resetTable();
                    }),
                Action::make('view_output')
                    ->label(__('Last output'))
                    ->icon('heroicon-o-document-text')
                    ->visible(fn (array $record): bool => $this->jobsAdvancedUi()
                        && app(SystemJobRunnerService::class)->latestRun($record['key']) !== null)
                    ->modalHeading(fn (array $record): string => $record['job_label'])
                    ->schema(fn (array $record): array => [
                        Placeholder::make('output')
                            ->label(__('Output'))
                            ->content(fn (): HtmlString => new HtmlString(
                                '<pre class="max-h-96 overflow-auto whitespace-pre-wrap text-xs">'
                                .e(app(SystemJobRunnerService::class)->latestRun($record['key'])?->output ?? '—')
                                .'</pre>'
                            )),
                    ]),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ]), TableGrouping::systemJobCatalog());
    }

    protected function configureJobsHistoryTable(Table $table): Table
    {
        return TableGrouping::apply($table
            ->query(SystemJobRun::query()->with('triggeredByUser'))
            ->columnManager(false)
            ->persistColumnsInSession(false)
            ->defaultSort('started_at', 'desc')
            ->heading(__('Run history'))
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        SystemJobRun::STATUS_SUCCESS => __('Success'),
                        SystemJobRun::STATUS_FAILED => __('Failed'),
                        SystemJobRun::STATUS_RUNNING => __('Running'),
                    ]),
            ])
            ->columns([
                TextColumn::make('job_key')
                    ->label(__('Job'))
                    ->formatStateUsing(fn (string $state): string => ScheduledJobRegistry::find($state)['label'] ?? $state)
                    ->searchable()
                    ->wrap()
                    ->toggleable(false),
                TextColumn::make('status')
                    ->badge()
                    ->toggleable(false)
                    ->searchable(false),
                TextColumn::make('started_at')
                    ->label(__('Started'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(false)
                    ->searchable(false),
                TextColumn::make('duration_ms')
                    ->label(__('Duration'))
                    ->formatStateUsing(fn (mixed $state): string => filled($state) ? ((int) $state).' ms' : '—')
                    ->alignEnd()
                    ->toggleable(false)
                    ->searchable(false)
                    ->sortable(),
                TextColumn::make('exit_code')
                    ->label(__('Exit code'))
                    ->placeholder(__('—'))
                    ->visible(fn (): bool => $this->jobsAdvancedUi())
                    ->searchable(false),
            ])
            ->recordActions(TableRecordActionGroups::wrap([
                Action::make('view_run')
                    ->label(__('Output'))
                    ->icon('heroicon-o-document-text')
                    ->schema([
                        Placeholder::make('run_output')
                            ->label(__('Output'))
                            ->content(fn (SystemJobRun $record): HtmlString => new HtmlString(
                                '<pre class="max-h-96 overflow-auto whitespace-pre-wrap text-xs">'.e($record->output ?? '—').'</pre>'
                            )),
                    ]),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ]), TableGrouping::systemJobRuns());
    }

    protected function getJobsTableQueryStringIdentifier(): ?string
    {
        return 'jobs_'.$this->jobsTab;
    }

    public function automationSchedulerIsPaused(): bool
    {
        return app(AutomationSchedulerGate::class)->isPaused();
    }

    public function automationSchedulerPauseReason(): ?string
    {
        return app(AutomationSchedulerGate::class)->reason();
    }

    protected function refreshHeaderActions(): void
    {
        foreach ($this->cachedHeaderActions as $previous) {
            if ($previous instanceof Action) {
                unset($this->cachedActions[$previous->getName()]);
            }

            if ($previous instanceof ActionGroup) {
                foreach ($previous->getFlatActions() as $flatAction) {
                    unset($this->cachedActions[$flatAction->getName()]);
                }
            }
        }

        $this->cachedHeaderActions = [];
        $this->cacheInteractsWithHeaderActions();
    }

    /**
     * Pause / resume scheduler + clear run history (shared by Jobs and Audit → Jobs).
     *
     * @return list<Action>
     */
    protected function jobsAutomationControlActions(): array
    {
        return [
            BusinessDayWindowRollbackHeaderAction::make(),
            Action::make('toggle_scheduler')
                ->label(fn (): string => app(AutomationSchedulerGate::class)->isPaused()
                    ? __('Resume scheduler')
                    : __('Pause scheduler'))
                ->icon(fn (): string => app(AutomationSchedulerGate::class)->isPaused()
                    ? 'heroicon-o-play'
                    : 'heroicon-o-pause')
                ->iconButton()
                ->tooltip(fn (): string => app(AutomationSchedulerGate::class)->isPaused()
                    ? __('Resume scheduler')
                    : __('Pause scheduler'))
                ->color(fn (): string => app(AutomationSchedulerGate::class)->isPaused() ? 'success' : 'warning')
                ->requiresConfirmation()
                ->modalHeading(fn (): string => app(AutomationSchedulerGate::class)->isPaused()
                    ? __('Resume scheduled automation?')
                    : __('Pause scheduled automation?'))
                ->modalDescription(fn (): string => app(AutomationSchedulerGate::class)->isPaused()
                    ? __('Scheduled jobs for this tenant will run again on the next cron tick.')
                    : __('Cron will keep waking every minute, but this tenant’s scheduled jobs will skip until you resume. Manual “Run now” still works.'))
                ->action(function (): void {
                    $scheduler = app(AutomationSchedulerGate::class);

                    if ($scheduler->isPaused()) {
                        $scheduler->resume();
                        Notification::make()
                            ->title(__('Scheduler resumed'))
                            ->success()
                            ->send();
                    } else {
                        $scheduler->pause();
                        Notification::make()
                            ->title(__('Scheduler paused'))
                            ->body(__('Scheduled automation is paused for this tenant.'))
                            ->warning()
                            ->send();
                    }

                    $this->refreshHeaderActions();
                    $this->forceRender();
                }),
            Action::make('clear_run_history')
                ->label(__('Clear run history'))
                ->icon('heroicon-o-trash')
                ->iconButton()
                ->tooltip(__('Clear run history'))
                ->color('danger')
                ->visible(fn (): bool => $this->jobsTab === 'history' && $this->jobsAdvancedUi())
                ->requiresConfirmation()
                ->modalHeading(__('Clear run history?'))
                ->modalDescription(__('Deletes finished automation runs for this tenant. In-progress runs are kept.'))
                ->action(function (): void {
                    $deleted = app(SystemJobRunnerService::class)->clearRunHistory();

                    Notification::make()
                        ->title(__('Run history cleared'))
                        ->body(__(':count run(s) deleted.', ['count' => $deleted]))
                        ->success()
                        ->send();

                    $this->resetTable();
                }),
        ];
    }
}
