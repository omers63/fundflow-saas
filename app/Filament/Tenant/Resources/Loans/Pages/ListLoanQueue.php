<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\Loans\Pages;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\LoanFilamentActions;
use App\Filament\Support\LoanListTableHeaderActions;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanQueuePriorityScoreService;
use Filament\Actions\BulkActionGroup;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListLoanQueue extends ListRecords
{
    protected static string $resource = LoanResource::class;

    protected static ?string $navigationLabel = 'Loan queue';

    protected static ?string $title = 'Loan queue';

    protected static ?string $slug = 'queue';

    protected static bool $shouldRegisterNavigation = false;

    #[Url(as: 'kind')]
    public string $queueKind = 'all';

    public function getTitle(): string|Htmlable
    {
        return __('Loan queue');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Review applications and disburse approved loans.');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }

    public function setQueueKind(string $kind): void
    {
        if (! in_array($kind, ['all', 'emergency', 'standard', 'partial'], true)) {
            return;
        }

        if ($this->queueKind === $kind) {
            return;
        }

        $this->queueKind = $kind;
        $this->resetTable();
    }

    /**
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return [
            ...parent::getWidgetData(),
            'context' => 'queue',
            'queueTab' => $this->activeTab ?? 'needs_decision',
        ];
    }

    public function getTabs(): array
    {
        return [
            'needs_decision' => Tab::make(__('Needs decision'))
                ->badge((string) Loan::query()->needsDecision()->count())
                ->badgeColor('warning'),
            'ready_to_disburse' => Tab::make(__('Ready to disburse'))
                ->badge((string) Loan::query()->readyToDisburse()->count())
                ->badgeColor('info'),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getTabsContentComponent(),
                View::make('filament.tenant.resources.loans.partials.queue-kind-tabs')
                    ->viewData(fn ($livewire): array => [
                        'queueKind' => $livewire->queueKind,
                    ]),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'fi-page-loan-queue',
        ];
    }

    protected function makeTable(): Table
    {
        $table = $this->makeBaseTable()
            ->query(fn (): Builder => $this->getTableQuery())
            ->modifyQueryUsing($this->modifyQueryWithActiveTab(...));

        return $this->table($table);
    }

    protected function getTableQuery(): Builder
    {
        $query = Loan::query()
            ->with(['member', 'loanTier', 'fundTier'])
            ->select('loans.*');

        $query = match ($this->activeTab ?? 'needs_decision') {
            'ready_to_disburse' => $query
                ->whereIn('loans.status', ['approved', 'partially_disbursed'])
                ->whereRaw('COALESCE(loans.amount_disbursed, 0) < COALESCE(loans.amount_approved, loans.amount_requested, 0)'),
            default => $query->where('loans.status', 'pending'),
        };

        $query = match ($this->queueKind) {
            'emergency' => $query->where('loans.is_emergency', true),
            'standard' => $query->where('loans.is_emergency', false),
            'partial' => $query->where('loans.status', 'partially_disbursed'),
            default => $query,
        };

        if (($this->activeTab ?? 'needs_decision') === 'needs_decision') {
            return app(LoanQueuePriorityScoreService::class)->applySort($query);
        }

        return $query
            ->orderBy('queue_position')
            ->orderBy('applied_at');
    }

    public function table(Table $table): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');
        $priorityService = app(LoanQueuePriorityScoreService::class);

        return TableGrouping::apply($table
            ->headerActions(LoanListTableHeaderActions::queue())
            ->columnManager(true)
            ->columns([
                TextColumn::make('priority_score')
                    ->label(__('Priority'))
                    ->state(fn (Loan $record): int => $priorityService->calculate($record))
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state >= 120 => 'danger',
                        $state >= 80 => 'warning',
                        default => 'gray',
                    })
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $priorityService->applySort($query, $direction))
                    ->alignCenter(),
                TextColumn::make('queue_position')
                    ->label(__('Queue #'))
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('applied_at')
                    ->label(__('Applied'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('waiting_days')
                    ->label(__('Waiting'))
                    ->state(fn (Loan $record): string => $record->applied_at
                        ? $record->applied_at->diffInDays(now()).'d'
                        : '—')
                    ->badge()
                    ->color(fn (Loan $record): string => match (true) {
                        $record->is_emergency => 'danger',
                        ! $record->applied_at => 'gray',
                        $record->applied_at->diffInDays(now()) >= 7 => 'danger',
                        $record->applied_at->diffInDays(now()) >= 3 => 'warning',
                        default => 'success',
                    })
                    ->sortable(query: fn ($query, string $direction) => $query->orderBy('applied_at', $direction === 'asc' ? 'desc' : 'asc')),
                TextColumn::make('member.name')
                    ->label(__('Member'))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('amount_requested')
                    ->label(__('Requested'))
                    ->money($currency)
                    ->sortable(),
                TextColumn::make('amount_approved')
                    ->label(__('Approved'))
                    ->money($currency)
                    ->placeholder('—'),
                TextColumn::make('amount_disbursed')
                    ->label(__('Disbursed'))
                    ->money($currency),
                TextColumn::make('fundTier.label')
                    ->label(__('Fund tier'))
                    ->placeholder('—'),
                TextColumn::make('is_emergency')
                    ->label(__('Emergency'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? __('Yes') : __('No'))
                    ->color(fn (bool $state): string => $state ? 'danger' : 'gray'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Loan::statusOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => Loan::statusColor($state)),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(Loan::statusOptions()),
                TernaryFilter::make('is_emergency')
                    ->label(__('Emergency')),
                DateColumnRangeFilter::make('applied_at', __('Applied')),
            ])
            ->recordActions(TableRecordActionGroups::wrap(LoanFilamentActions::queueTableActions()))
            ->toolbarActions([
                BulkActionGroup::make(LoanFilamentActions::bulkActions()),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25), TableGrouping::loanQueue());
    }
}
