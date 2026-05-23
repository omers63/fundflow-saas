<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\MemberTableColumns;
use App\Filament\Support\MigrationStubFilamentActions;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Resources\Members\RelationManagers\MigrationStubsRelationManager;
use App\Filament\Tenant\Support\TenantNavigation;
use App\Models\Tenant\Member;
use App\Models\Tenant\MigrationCycleStub;
use App\Models\Tenant\Setting;
use App\Services\MigrationCycleService;
use App\Services\MigrationWorkflowService;
use App\Support\Lang;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

class MigrationWorkflowPage extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Migrations';

    protected static string|UnitEnum|null $navigationGroup = TenantNavigation::GROUP_SYSTEM;

    protected static ?int $navigationSort = TenantNavigation::SORT_MIGRATIONS;

    protected static ?string $slug = 'migration-workflow';

    protected static bool $shouldRegisterNavigation = true;

    protected string $view = 'filament.tenant.pages.migration-workflow';

    public string $migrationWorkflowTab = 'queue';

    public function mount(): void
    {
        $tab = request()->query('tab');

        if (is_string($tab) && in_array($tab, ['queue', 'stubs', 'not_started'], true)) {
            $this->migrationWorkflowTab = $tab;
        }
    }

    public function setMigrationTab(string $tab): void
    {
        if (!in_array($tab, ['queue', 'stubs', 'not_started'], true)) {
            return;
        }

        if ($this->migrationWorkflowTab === $tab) {
            return;
        }

        $this->migrationWorkflowTab = $tab;
        $this->resetMigrationWorkflowTableColumns();
        $this->resetTable();
    }

    /**
     * Each tab uses a different table schema; Filament column state is keyed per Livewire
     * class only, so we must reset it when switching tabs or columns render blank/hidden.
     */
    protected function resetMigrationWorkflowTableColumns(): void
    {
        $this->tableColumns = [];
        $this->cachedDefaultTableColumnState = null;
    }

    private function configureMigrationWorkflowTable(Table $table, bool $withExplorerControls = false): Table
    {
        $table = $table->persistColumnsInSession(false);

        if (!$withExplorerControls) {
            $table->columnManager(false);
        }

        return $table;
    }

    protected function getTableQueryStringIdentifier(): ?string
    {
        return 'migration_workflow_' . $this->migrationWorkflowTab;
    }

    public function getTitle(): string
    {
        return __('Migrations');
    }

    public function getSubheading(): ?string
    {
        $counts = app(MigrationWorkflowService::class)->queueCounts();

        return __(':pending member(s) in migration · :stubs open stub(s) · :not_started not started', [
            'pending' => $counts['pending'],
            'stubs' => $counts['stubs'],
            'not_started' => $counts['not_started'],
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('allMembers')
                ->label(__('All members'))
                ->icon('heroicon-o-users')
                ->url(MemberResource::getUrl('index'))
                ->color('gray'),
        ];
    }

    public function table(Table $table): Table
    {
        return match ($this->migrationWorkflowTab) {
            'stubs' => $this->stubsTable($table),
            'not_started' => $this->notStartedTable($table),
            default => $this->queueTable($table),
        };
    }

    private function queueTable(Table $table): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');
        $workflow = app(MigrationWorkflowService::class);

        return TableGrouping::apply(
            $this->configureMigrationWorkflowTable($table, withExplorerControls: true)
                ->query(fn(): Builder => $workflow->pendingMembersQuery())
                ->heading(__('Members in migration'))
                ->columns([
                    MemberTableColumns::number(label: __('Member #'))
                        ->searchable()
                        ->sortable(),
                    MemberTableColumns::name(label: __('Member'))
                        ->searchable()
                        ->sortable()
                        ->wrap(),
                    TextColumn::make('migration_cutoff_date')
                        ->label(__('Cutoff'))
                        ->date()
                        ->sortable()
                        ->placeholder(__('—')),
                    TextColumn::make('unresolved_stubs')
                        ->label(__('Open stubs'))
                        ->state(fn(Member $record): int => $workflow->unresolvedStubCountForMember($record))
                        ->alignEnd()
                        ->sortable(query: function (Builder $query, string $direction): Builder {
                            return $query->withCount([
                                'migrationStubs as unresolved_stubs_count' => fn(Builder $stub): Builder => $stub->unresolved(),
                            ])->orderBy('unresolved_stubs_count', $direction);
                        }),
                    TextColumn::make('opening_balances_posted_at')
                        ->label(__('Opening balances'))
                        ->dateTime()
                        ->placeholder(__('Not posted')),
                    TextColumn::make('partial_clearance_granted_at')
                        ->label(__('Partial clearance'))
                        ->dateTime()
                        ->placeholder(__('—'))
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('joined_at')
                        ->date()
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('parent.name')
                        ->label(__('Parent'))
                        ->placeholder(__('Independent'))
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('email')
                        ->placeholder(__('—'))
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('monthly_contribution_amount')
                        ->label(__('Monthly'))
                        ->money($currency)
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('opening_cash_balance')
                        ->label(__('Opening cash'))
                        ->money($currency)
                        ->placeholder(__('—'))
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('opening_fund_balance')
                        ->label(__('Opening fund'))
                        ->money($currency)
                        ->placeholder(__('—'))
                        ->toggleable(isToggledHiddenByDefault: true),
                ])
                ->filters([
                    TernaryFilter::make('opening_balances_posted_at')
                        ->label(__('Opening balances posted'))
                        ->trueLabel(__('Posted'))
                        ->falseLabel(__('Not posted')),
                    TernaryFilter::make('partial_clearance_granted_at')
                        ->label(__('Partial clearance'))
                        ->trueLabel(__('Granted'))
                        ->falseLabel(__('Not granted')),
                    Filter::make('has_unresolved_stubs')
                        ->label(__('Has open stubs'))
                        ->query(fn(Builder $query): Builder => $query->whereHas(
                            'migrationStubs',
                            fn(Builder $stub): Builder => $stub->unresolved(),
                        ))
                        ->toggle(),
                    SelectFilter::make('parent_member_id')
                        ->label(__('Parent'))
                        ->relationship('parent', 'name')
                        ->searchable()
                        ->preload(),
                    DateColumnRangeFilter::make('migration_cutoff_date', __('Cutoff')),
                    DateColumnRangeFilter::make('joined_at', __('Joined')),
                ])
                ->defaultSort('name')
                ->recordActions(TableRecordActionGroups::wrap([
                    Action::make('openMember')
                        ->label(__('Open member'))
                        ->icon('heroicon-o-user')
                        ->url(fn(Member $record): string => MemberResource::getUrl('edit', ['record' => $record])),
                    Action::make('grantPartialClearance')
                        ->label(__('Grant partial clearance'))
                        ->icon('heroicon-o-shield-check')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalDescription(__('Allows active operation while escalated historical cycles remain under investigation.'))
                        ->visible(fn(Member $record): bool => $record->partial_clearance_granted_at === null)
                        ->schema([
                            Textarea::make('notes')
                                ->label(__('Reason'))
                                ->required()
                                ->rows(3),
                        ])
                        ->action(function (Member $record, array $data, MigrationCycleService $migration): void {
                            try {
                                $migration->grantPartialClearance($record, (string) $data['notes']);
                            } catch (\InvalidArgumentException $exception) {
                                Notification::make()
                                    ->title(__('Cannot grant partial clearance'))
                                    ->body($exception->getMessage())
                                    ->danger()
                                    ->send();

                                return;
                            }

                            Notification::make()->title(__('Partial clearance granted'))->success()->send();
                        }),
                    Action::make('clearMigration')
                        ->label(__('Clear for active operation'))
                        ->icon('heroicon-o-check-badge')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (Member $record, MigrationCycleService $migration): void {
                            try {
                                $migration->clearMemberForActiveOperation($record);
                            } catch (\InvalidArgumentException $exception) {
                                Notification::make()
                                    ->title(__('Cannot clear member'))
                                    ->body($exception->getMessage())
                                    ->danger()
                                    ->send();

                                return;
                            }

                            Notification::make()->title(__('Member cleared for active operation'))->success()->send();
                        }),
                ]))
                ->toolbarActions([
                    BulkActionGroup::make([
                        TableToolbar::refreshBulkAction(),
                    ]),
                ]),
            TableGrouping::migrationQueueMembers(),
        );
    }

    private function stubsTable(Table $table): Table
    {
        $workflow = app(MigrationWorkflowService::class);
        $currency = Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply(
            $this->configureMigrationWorkflowTable($table, withExplorerControls: true)
                ->query(fn(): Builder => $workflow->openStubsQuery())
                ->heading(__('Open migration cycle stubs'))
                ->columns([
                    TextColumn::make('member.member_number')
                        ->label(__('Member #'))
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('member.name')
                        ->label(__('Member'))
                        ->searchable()
                        ->sortable()
                        ->wrap(),
                    TextColumn::make('cycle_date')
                        ->label(__('Cycle date'))
                        ->date()
                        ->sortable(),
                    TextColumn::make('amount_due')
                        ->money($currency)
                        ->sortable(),
                    TextColumn::make('status')
                        ->badge()
                        ->formatStateUsing(fn(string $state): string => match ($state) {
                            'unresolved' => __('Unresolved'),
                            'closed' => __('Closed'),
                            'escalated' => __('Escalated'),
                            default => ucfirst($state),
                        }),
                    TextColumn::make('classification')
                        ->badge()
                        ->placeholder(__('—'))
                        ->formatStateUsing(fn(?string $state): string => match ($state) {
                            MigrationCycleStub::CLASS_WAIVED => __('Waived'),
                            MigrationCycleStub::CLASS_BACKDATED_PAID => __('Backdated paid'),
                            MigrationCycleStub::CLASS_BACKDATED_DUE => __('Backdated due'),
                            MigrationCycleStub::CLASS_OB_ABSORBED => __('Opening balance absorbed'),
                            MigrationCycleStub::CLASS_ESCALATED => __('Escalated'),
                            default => $state !== null ? ucfirst($state) : __('—'),
                        }),
                    TextColumn::make('resolution_method')
                        ->placeholder(__('—'))
                        ->toggleable(isToggledHiddenByDefault: true),
                    IconColumn::make('late_fee_exempt')
                        ->boolean()
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('notes')
                        ->wrap()
                        ->limit(40)
                        ->placeholder(__('—'))
                        ->toggleable(isToggledHiddenByDefault: true),
                ])
                ->filters([
                    SelectFilter::make('status')
                        ->options([
                            'unresolved' => __('Unresolved'),
                            'closed' => __('Closed'),
                            'escalated' => __('Escalated'),
                        ]),
                    SelectFilter::make('classification')
                        ->options(Lang::transOptions([
                            MigrationCycleStub::CLASS_WAIVED => __('Waived'),
                            MigrationCycleStub::CLASS_BACKDATED_PAID => __('Backdated paid'),
                            MigrationCycleStub::CLASS_BACKDATED_DUE => __('Backdated due'),
                            MigrationCycleStub::CLASS_OB_ABSORBED => __('Opening balance absorbed'),
                            MigrationCycleStub::CLASS_ESCALATED => __('Escalated'),
                        ])),
                    Filter::make('unclassified')
                        ->label(__('Unclassified only'))
                        ->query(fn(Builder $query): Builder => $query->whereNull('classification'))
                        ->toggle(),
                    SelectFilter::make('member_id')
                        ->label(__('Member'))
                        ->relationship('member', 'name')
                        ->searchable()
                        ->preload(),
                    DateColumnRangeFilter::make('cycle_date', __('Cycle date')),
                    TernaryFilter::make('late_fee_exempt')
                        ->label(__('Late fee exempt')),
                ])
                ->defaultSort('cycle_date')
                ->recordActions(TableRecordActionGroups::wrap([
                    MigrationStubFilamentActions::classifyRecordAction(),
                    Action::make('deleteStub')
                        ->label(__('Delete'))
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function (MigrationCycleStub $record, MigrationCycleService $migration): void {
                            $member = $record->member;

                            if ($member === null) {
                                return;
                            }

                            $deleted = $migration->deleteStubsForMember($member, collect([$record]));

                            $notification = Notification::make()
                                ->title($deleted > 0
                                    ? __('Cycle deleted')
                                    : __('Nothing deleted'));

                            if ($deleted > 0) {
                                $notification->success();
                            } else {
                                $notification->warning();
                            }

                            $notification->send();
                        }),
                    Action::make('openMember')
                        ->label(__('Open member'))
                        ->icon('heroicon-o-user')
                        ->url(fn(MigrationCycleStub $record): string => MemberResource::editUrlWithRelationManager(
                            (int) $record->member_id,
                            MigrationStubsRelationManager::class,
                        )),
                ]))
                ->toolbarActions([
                    BulkActionGroup::make([
                        MigrationStubFilamentActions::classifySelectedBulkAction(),
                        BulkAction::make('deleteSelectedStubs')
                            ->label(__('Delete selected'))
                            ->icon('heroicon-o-trash')
                            ->color('danger')
                            ->requiresConfirmation()
                            ->modalDescription(__('Permanently removes the selected open migration cycle stubs.'))
                            ->action(function (Collection $records, MigrationCycleService $migration): void {
                                $byMember = $records
                                    ->filter(fn($record): bool => $record instanceof MigrationCycleStub)
                                    ->groupBy('member_id');

                                $deleted = 0;

                                foreach ($byMember as $memberId => $stubs) {
                                    $member = Member::query()->find($memberId);

                                    if ($member === null) {
                                        continue;
                                    }

                                    $deleted += $migration->deleteStubsForMember($member, $stubs);
                                }

                                Notification::make()
                                    ->title(__(':count stub(s) deleted', ['count' => $deleted]))
                                    ->success()
                                    ->send();
                            }),
                        TableToolbar::refreshBulkAction(),
                    ]),
                ]),
            TableGrouping::migrationCycleStubs(),
        );
    }

    private function notStartedTable(Table $table): Table
    {
        $workflow = app(MigrationWorkflowService::class);

        $currency = Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply(
            $this->configureMigrationWorkflowTable($table, withExplorerControls: true)
                ->query(fn(): Builder => $workflow->notStartedMembersQuery())
                ->heading(__('Members not yet in migration'))
                ->columns([
                    MemberTableColumns::number(label: __('Member #'))
                        ->searchable()
                        ->sortable(),
                    MemberTableColumns::name(label: __('Member'))
                        ->searchable()
                        ->sortable()
                        ->wrap(),
                    TextColumn::make('joined_at')
                        ->date()
                        ->sortable(),
                    TextColumn::make('monthly_contribution_amount')
                        ->label(__('Monthly contribution'))
                        ->money($currency)
                        ->sortable(),
                    TextColumn::make('parent.name')
                        ->label(__('Parent'))
                        ->placeholder(__('Independent'))
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('email')
                        ->placeholder(__('—'))
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('phone')
                        ->placeholder(__('—'))
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('status')
                        ->badge()
                        ->formatStateUsing(fn(string $state): string => Member::statusOptions()[$state] ?? ucfirst($state))
                        ->color(fn(string $state): string => Member::statusBadgeColor($state))
                        ->toggleable(isToggledHiddenByDefault: true),
                ])
                ->filters([
                    SelectFilter::make('parent_member_id')
                        ->label(__('Parent'))
                        ->relationship('parent', 'name')
                        ->searchable()
                        ->preload(),
                    Filter::make('dependents_only')
                        ->label(__('Dependents only'))
                        ->query(fn(Builder $query): Builder => $query->whereNotNull('parent_member_id'))
                        ->toggle(),
                    Filter::make('independent_only')
                        ->label(__('Independent only'))
                        ->query(fn(Builder $query): Builder => $query->whereNull('parent_member_id'))
                        ->toggle(),
                    DateColumnRangeFilter::make('joined_at', __('Joined')),
                ])
                ->defaultSort('name')
                ->recordActions(TableRecordActionGroups::wrap([
                    Action::make('beginMigration')
                        ->label(__('Begin migration'))
                        ->icon('heroicon-o-document-plus')
                        ->color('primary')
                        ->schema([
                            Hidden::make('member_id')
                                ->required(),
                            DatePicker::make('cutoff')
                                ->label(__('Cutoff date'))
                                ->required(),
                        ])
                        ->fillForm(fn(Member $record): array => [
                            'member_id' => $record->getKey(),
                            'cutoff' => $record->joined_at?->toDateString()
                                ?? now()->startOfMonth()->toDateString(),
                        ])
                        ->action(function (array $data, MigrationCycleService $migration): void {
                            $member = Member::query()->findOrFail((int) $data['member_id']);

                            try {
                                $count = $migration->generateHistoricalStubs(
                                    $member,
                                    Carbon::parse((string) $data['cutoff']),
                                );
                            } catch (\InvalidArgumentException $exception) {
                                Notification::make()
                                    ->title(__('Cannot generate stubs'))
                                    ->body($exception->getMessage())
                                    ->danger()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title(__(':count stub(s) created for :name', [
                                    'count' => $count,
                                    'name' => $member->name,
                                ]))
                                ->success()
                                ->send();

                            $this->redirect(MemberResource::editUrlWithRelationManager(
                                $member,
                                MigrationStubsRelationManager::class,
                            ));
                        }),
                    Action::make('openMember')
                        ->label(__('Open member'))
                        ->icon('heroicon-o-user')
                        ->url(fn(Member $record): string => MemberResource::getUrl('edit', ['record' => $record])),
                ]))
                ->toolbarActions([
                    BulkActionGroup::make([
                        TableToolbar::refreshBulkAction(),
                    ]),
                ]),
            TableGrouping::migrationNotStartedMembers(),
        );
    }
}
