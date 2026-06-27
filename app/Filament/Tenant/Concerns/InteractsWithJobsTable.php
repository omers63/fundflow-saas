<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Concerns;

use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\SystemJobRun;
use App\Services\SystemJobRunnerService;
use App\Support\ScheduledJobRegistry;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Placeholder;
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
        if (! in_array($tab, ['status', 'catalog', 'history'], true)) {
            return;
        }

        if ($this->jobsTab === $tab) {
            return;
        }

        $this->jobsTab = $tab;
        $this->tableSort = null;

        if (method_exists($this, 'reconfigureTableForSideTab')) {
            $this->reconfigureTableForSideTab();
        } else {
            $this->resetJobsTableColumns();
        }

        $this->resetTable();
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
                    ->wrap(),
                TextColumn::make('category')
                    ->badge(),
                TextColumn::make('schedule')
                    ->label(__('Schedule'))
                    ->wrap(),
                TextColumn::make('last_status')
                    ->label(__('Last run'))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        SystemJobRun::STATUS_SUCCESS => 'success',
                        SystemJobRun::STATUS_FAILED => 'danger',
                        SystemJobRun::STATUS_RUNNING => 'warning',
                        default => 'gray',
                    })
                    ->placeholder(__('Never')),
                TextColumn::make('last_started_at')
                    ->label(__('Last started'))
                    ->dateTime()
                    ->placeholder(__('—')),
                TextColumn::make('last_duration_ms')
                    ->label(__('Duration'))
                    ->formatStateUsing(fn (?int $state): string => $state ? $state.' ms' : '—')
                    ->visible(fn (): bool => $this->jobsAdvancedUi()),
            ])
            ->recordActions(TableRecordActionGroups::wrap([
                Action::make('run')
                    ->label(__('Run now'))
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (array $record): void {
                        try {
                            $result = app(SystemJobRunnerService::class)->run($record['key']);
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
            ->query(SystemJobRun::query()->with('triggeredByUser')->latestFirst())
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
                    ->wrap(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('exit_code')
                    ->label(__('Exit code'))
                    ->placeholder(__('—'))
                    ->visible(fn (): bool => $this->jobsAdvancedUi()),
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
}
