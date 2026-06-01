<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Resources\ReconciliationExceptions\ReconciliationExceptionResource;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\SystemJobRun;
use App\Services\ReconciliationService;
use App\Services\SystemJobRunnerService;
use App\Support\BatchPostingGate;
use App\Support\ScheduledJobRegistry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use UnitEnum;

class JobsPage extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_SYSTEM;

    protected static ?string $navigationLabel = 'Jobs & commands';

    protected static ?string $slug = 'jobs';

    protected static ?int $navigationSort = TenantNavigation::SORT_JOBS;

    protected static bool $shouldRegisterNavigation = true;

    protected static ?string $title = 'Jobs & commands';

    public static function canAccess(): bool
    {
        return auth()->guard('tenant')->check();
    }

    protected string $view = 'filament.tenant.pages.jobs';

    public string $jobsTab = 'catalog';

    public function setJobsTab(string $tab): void
    {
        if (!in_array($tab, ['catalog', 'history'], true)) {
            return;
        }

        if ($this->jobsTab === $tab) {
            return;
        }

        $this->jobsTab = $tab;
        $this->resetJobsTableColumns();
        $this->resetTable();
    }

    /**
     * Catalog uses custom records; history uses Eloquent. Column state is per Livewire class only.
     */
    protected function resetJobsTableColumns(): void
    {
        $this->tableColumns = [];
        $this->cachedDefaultTableColumnState = null;
    }

    public function getTitle(): string
    {
        return __('Jobs & commands');
    }

    public function getSubheading(): ?string
    {
        $gate = app(BatchPostingGate::class);

        if ($gate->isHalted()) {
            return __('Batch posting halted: :reason', ['reason' => $gate->reason() ?? __('Critical reconciliation issue')]);
        }

        return __('Monitor and run scheduled fund operations for this tenant.');
    }

    protected function getHeaderActions(): array
    {
        $gate = app(BatchPostingGate::class);

        return [
            Action::make('clear_halt')
                ->label(__('Clear posting halt'))
                ->icon('heroicon-o-play')
                ->color('warning')
                ->visible($gate->isHalted())
                ->requiresConfirmation()
                ->action(function () use ($gate): void {
                    $gate->clear();
                    Notification::make()->title(__('Batch posting halt cleared'))->success()->send();
                }),
            Action::make('open_reconciliation')
                ->label(__('Reconciliation queue'))
                ->icon('heroicon-o-shield-exclamation')
                ->url(ReconciliationExceptionResource::getUrl('index')),
            Action::make('run_reconciliation')
                ->label(__('Run reconciliation'))
                ->icon('heroicon-o-shield-check')
                ->color('primary')
                ->action(function (): void {
                    $result = app(ReconciliationService::class)->runNightlyBatch();

                    Notification::make()
                        ->title($result['halted'] ? __('Reconciliation halted') : __('Reconciliation complete'))
                        ->body(__('Raised: :raised | Resolved: :resolved', [
                            'raised' => $result['raised'],
                            'resolved' => $result['resolved'],
                        ]))
                        ->color($result['halted'] ? 'danger' : 'success')
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        if ($this->jobsTab === 'history') {
            return $this->historyTable($table);
        }

        return $this->catalogTable($table);
    }

    protected function catalogTable(Table $table): Table
    {
        return TableGrouping::apply($table
            ->records(fn(): Collection => app(SystemJobRunnerService::class)->catalogRecords())
            ->columnManager(false)
            ->persistColumnsInSession(false)
            ->heading(__('Scheduled jobs'))
            ->filters([
                SelectFilter::make('category')
                    ->options(fn(): array => collect(app(SystemJobRunnerService::class)->catalogRecords())
                        ->pluck('category', 'category')
                        ->unique()
                        ->sort()
                        ->all()),
            ])
            ->columns([
                TextColumn::make('job_label')
                    ->label(__('Job'))
                    ->sortable(false)
                    ->searchable(false)
                    ->wrap(),
                TextColumn::make('category')
                    ->sortable(false)
                    ->searchable(false)
                    ->badge(),
                TextColumn::make('schedule')
                    ->label(__('Schedule'))
                    ->sortable(false)
                    ->searchable(false)
                    ->wrap(),
                TextColumn::make('last_status')
                    ->label(__('Last run'))
                    ->sortable(false)
                    ->searchable(false)
                    ->badge()
                    ->color(fn(?string $state): string => match ($state) {
                        SystemJobRun::STATUS_SUCCESS => 'success',
                        SystemJobRun::STATUS_FAILED => 'danger',
                        SystemJobRun::STATUS_RUNNING => 'warning',
                        default => 'gray',
                    })
                    ->placeholder(__('Never')),
                TextColumn::make('last_started_at')
                    ->label(__('Last started'))
                    ->sortable(false)
                    ->searchable(false)
                    ->dateTime()
                    ->placeholder(__('—')),
                TextColumn::make('last_duration_ms')
                    ->label(__('Duration'))
                    ->sortable(false)
                    ->searchable(false)
                    ->formatStateUsing(fn(?int $state): string => $state ? $state . ' ms' : '—'),
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
                    ->visible(fn(array $record): bool => app(SystemJobRunnerService::class)->latestRun($record['key']) !== null)
                    ->modalHeading(fn(array $record): string => $record['job_label'])
                    ->schema(fn(array $record): array => [
                        Placeholder::make('output')
                            ->label(__('Output'))
                            ->content(fn(): HtmlString => new HtmlString(
                                '<pre class="text-xs whitespace-pre-wrap max-h-96 overflow-auto">'
                                . e(app(SystemJobRunnerService::class)->latestRun($record['key'])?->output ?? '—')
                                . '</pre>'
                            )),
                    ]),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ]), TableGrouping::systemJobCatalog());
    }

    protected function historyTable(Table $table): Table
    {
        return TableGrouping::apply($table
            ->query(SystemJobRun::query()->with('triggeredByUser')->latestFirst())
            ->columnManager(false)
            ->persistColumnsInSession(false)
            ->defaultSort('started_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        SystemJobRun::STATUS_SUCCESS => __('Success'),
                        SystemJobRun::STATUS_FAILED => __('Failed'),
                        SystemJobRun::STATUS_RUNNING => __('Running'),
                    ]),
                SelectFilter::make('trigger')
                    ->options([
                        SystemJobRun::TRIGGER_SCHEDULE => __('Schedule'),
                        SystemJobRun::TRIGGER_MANUAL => __('Manual'),
                    ]),
            ])
            ->heading(__('Run history'))
            ->emptyStateHeading(__('No runs yet'))
            ->emptyStateDescription(__('Manual and scheduled job runs will appear here.'))
            ->columns([
                TextColumn::make('job_key')
                    ->label(__('Job'))
                    ->formatStateUsing(fn(string $state): string => ScheduledJobRegistry::find($state)['label'] ?? $state)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('trigger')
                    ->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        SystemJobRun::STATUS_SUCCESS => 'success',
                        SystemJobRun::STATUS_FAILED => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('exit_code')
                    ->label(__('Exit'))
                    ->placeholder(__('—')),
                TextColumn::make('duration_ms')
                    ->label(__('Ms'))
                    ->placeholder(__('—')),
                TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('triggeredByUser.name')
                    ->label(__('By'))
                    ->placeholder(__('Schedule')),
            ])
            ->recordActions(TableRecordActionGroups::wrap([
                Action::make('view_run')
                    ->label(__('Output'))
                    ->icon('heroicon-o-document-text')
                    ->modalHeading(fn(SystemJobRun $record): string => $record->job_key)
                    ->schema([
                        Placeholder::make('run_output')
                            ->label(__('Output'))
                            ->content(fn(SystemJobRun $record): HtmlString => new HtmlString(
                                '<pre class="text-xs whitespace-pre-wrap max-h-96 overflow-auto">' . e($record->output ?? '—') . '</pre>'
                            )),
                    ]),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ]), TableGrouping::systemJobRuns());
    }

    protected function getTableQueryStringIdentifier(): ?string
    {
        return 'jobs_' . $this->jobsTab;
    }
}
