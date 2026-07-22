<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tables\Columns\Summarizers\LoanRemainingToDisburseSum;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanQueueProjectionService;
use App\Services\Loans\LoanQueueService;
use App\Support\WaitingDuration;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class LoanQueueTable
{
    public static function queueQuery(string $tab, LoanQueueService $queue): Builder
    {
        return match ($tab) {
            'process' => $queue->processQuery(),
            'completed' => $queue->completedQuery(),
            default => $queue->intakeQuery(),
        };
    }

    public static function configure(Table $table, string $queueTab, LoanQueueService $queue): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');
        $projections = $queue->projections();

        $columns = match ($queueTab) {
            'process' => self::processColumns($currency, $queue),
            'completed' => self::completedColumns($currency),
            default => self::intakeColumns($currency),
        };

        if ($queueTab !== 'completed') {
            $columns[] = self::projectedColumn($queueTab, $projections);
        }

        $table = match ($queueTab) {
            'process' => $table->defaultSort('queue_position'),
            'completed' => $table->defaultSort('settled_at', 'desc'),
            default => $table->defaultSort(
                fn (Builder $query, string $direction): Builder => $query
                    ->orderByDesc('loans.is_emergency')
                    ->orderBy('loans.applied_at', $direction),
            ),
        };

        $filters = $queueTab === 'completed'
            ? self::completedFilters()
            : self::activeQueueFilters();

        $bulkActions = $queueTab === 'completed'
            ? [TableToolbar::refreshBulkAction()]
            : LoanFilamentActions::bulkActions();

        return TableGrouping::apply($table
            ->headerActions(LoanListTableHeaderActions::queue())
            ->columnManager(true)
            ->columns($columns)
            ->filters($filters)
            ->recordActions(TableRecordActionGroups::wrap(LoanFilamentActions::queueTableActions($queueTab)))
            ->toolbarActions([
                BulkActionGroup::make($bulkActions),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25), TableGrouping::loanQueue());
    }

    /**
     * @return array<int, mixed>
     */
    private static function activeQueueFilters(): array
    {
        return [
            SelectFilter::make('queue_kind')
                ->label(__('Loan kind'))
                ->options([
                    'emergency' => __('Emergency'),
                    'standard' => __('Standard'),
                    'partial' => __('Partial disbursement'),
                ])
                ->query(function (Builder $query, array $data): Builder {
                    return match ($data['value'] ?? null) {
                        'emergency' => $query->where('loans.is_emergency', true),
                        'standard' => $query->where('loans.is_emergency', false),
                        'partial' => $query->where('loans.status', 'partially_disbursed'),
                        default => $query,
                    };
                }),
            SelectFilter::make('fund_tier_id')
                ->label(__('Fund tier'))
                ->options(fn(): array => FundTier::query()
                    ->where('is_active', true)
                    ->orderBy('tier_number')
                    ->pluck('label', 'id')
                    ->all())
                ->query(function (Builder $query, array $data): Builder {
                    $raw = $data['value'] ?? null;
                    if ($raw === null || $raw === '') {
                        return $query;
                    }

                    $tierId = (int) $raw;
                    $emergencyId = FundTier::emergency()?->id;

                    return $query->where(function (Builder $outer) use ($tierId, $emergencyId): void {
                        $outer->where('loans.fund_tier_id', $tierId)
                            ->orWhere(function (Builder $expected) use ($tierId, $emergencyId): void {
                                $expected->whereNull('loans.fund_tier_id');

                                if ($emergencyId !== null && $emergencyId === $tierId) {
                                    $expected->where('loans.is_emergency', true);

                                    return;
                                }

                                $expected->where('loans.is_emergency', false)
                                    ->where(function (Builder $match) use ($tierId): void {
                                        $match->whereHas(
                                            'loanTier',
                                            fn(Builder $q) => $q->where('fund_tier_id', $tierId),
                                        )->orWhere(function (Builder $byAmount) use ($tierId): void {
                                            $byAmount->whereNull('loans.loan_tier_id')
                                                ->whereExists(function ($sub) use ($tierId): void {
                                                    $sub->selectRaw('1')
                                                        ->from('loan_tiers')
                                                        ->whereColumn('loan_tiers.min_amount', '<=', 'loans.amount_requested')
                                                        ->whereColumn('loan_tiers.max_amount', '>=', 'loans.amount_requested')
                                                        ->where('loan_tiers.is_active', true)
                                                        ->where('loan_tiers.fund_tier_id', $tierId)
                                                        ->whereNull('loan_tiers.deleted_at');
                                                });
                                        });
                                    });
                            });
                    });
                }),
            TernaryFilter::make('is_emergency')
                ->label(__('Emergency')),
            DateColumnRangeFilter::make('applied_at', __('Applied')),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private static function completedFilters(): array
    {
        return [
            SelectFilter::make('status')
                ->label(__('Settlement'))
                ->options([
                    'completed' => __('Repaid'),
                    'early_settled' => __('Repaid early'),
                ]),
            SelectFilter::make('fund_tier_id')
                ->label(__('Fund tier'))
                ->options(fn(): array => FundTier::withTrashed()
                    ->orderBy('tier_number')
                    ->get()
                    ->mapWithKeys(fn(FundTier $tier): array => [
                        $tier->id => $tier->trashed()
                            ? __(':label (archived)', ['label' => $tier->label])
                            : $tier->label,
                    ])
                    ->all())
                ->query(function (Builder $query, array $data): Builder {
                    $raw = $data['value'] ?? null;
                    if ($raw === null || $raw === '') {
                        return $query;
                    }

                    return $query->where('loans.fund_tier_id', (int) $raw);
                }),
            TernaryFilter::make('is_emergency')
                ->label(__('Emergency')),
            DateColumnRangeFilter::make('settled_at', __('Completed')),
        ];
    }

    private static function projectedColumn(string $queueTab, LoanQueueProjectionService $projections): TextColumn
    {
        $column = TextColumn::make('projected_wait')
            ->label($queueTab === 'process' ? __('Projected disbursement') : __('Projected approval'))
            ->state(fn (Loan $record): string => $projections->labelFor($record))
            ->badge()
            ->color(fn(Loan $record): string => $projections->projectionFor($record)['ready_now'] ? 'success' : 'info')
            ->searchable(false);

        if ($queueTab === 'process') {
            return $column->sortable(query: function (Builder $query, string $direction): Builder {
                $dir = strtolower($direction) === 'desc' ? 'desc' : 'asc';

                if (! self::queryHasFundTiersJoin($query)) {
                    $query->leftJoin('fund_tiers', 'fund_tiers.id', '=', 'loans.fund_tier_id');
                }

                return $query
                    ->orderBy('fund_tiers.tier_number', $dir)
                    ->orderByRaw('loans.queue_position IS NULL, loans.queue_position '.$dir);
            });
        }

        return $column->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
            'applied_at',
            strtolower($direction) === 'asc' ? 'desc' : 'asc',
        ));
    }

    private static function queryHasFundTiersJoin(Builder $query): bool
    {
        foreach ($query->getQuery()->joins ?? [] as $join) {
            if ($join->table === 'fund_tiers') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, Sum>
     */
    private static function moneySum(string $currency, string $label): array
    {
        return [
            Sum::make()
                ->label(fn(): string => $label)
                ->formatStateUsing(
                    fn($state): ?string => MoneyDisplay::tableSummaryHtml($state, $currency)
                )
                ->html(),
        ];
    }

    /**
     * @return array<int, TextColumn>
     */
    private static function intakeColumns(string $currency): array
    {
        return [
            TextColumn::make('is_emergency')
                ->label(__('Lane'))
                ->badge()
                ->formatStateUsing(fn (bool $state): string => $state ? __('Emergency') : __('Standard'))
                ->color(fn (bool $state): string => $state ? 'danger' : 'gray'),
            TextColumn::make('applied_at')
                ->label(__('Applied'))
                ->dateTime()
                ->sortable(),
            TextColumn::make('waiting_days')
                ->label(__('Waiting'))
                ->state(fn (Loan $record): string => WaitingDuration::format($record->applied_at))
                ->badge()
                ->color(fn (Loan $record): string => match (true) {
                    $record->is_emergency => 'danger',
                    ! $record->applied_at => 'gray',
                    WaitingDuration::days($record->applied_at) >= 7 => 'danger',
                    WaitingDuration::days($record->applied_at) >= 3 => 'warning',
                    default => 'success',
                })
                ->searchable(false)
                ->sortable(query: fn ($query, string $direction) => $query->orderBy('applied_at', $direction === 'asc' ? 'desc' : 'asc')),
            MemberTableColumns::relationNumber(),
            TextColumn::make('member.name')
                ->label(__('Member'))
                ->searchable()
                ->wrap(),
            TextColumn::make('amount_requested')
                ->label(__('Requested'))
                ->money($currency)
                ->sortable()
                ->summarize(self::moneySum($currency, __('Requested'))),
            TextColumn::make('loanTier.label')
                ->label(__('Loan tier'))
                ->placeholder('—'),
            TextColumn::make('expected_fund_tier')
                ->label(__('Fund tier'))
                ->state(fn (Loan $record): string => FundTier::resolveForLoan($record)?->label ?? '—')
                ->badge()
                ->color(fn(Loan $record): string => $record->is_emergency ? 'danger' : 'gray')
                ->searchable(false)
                ->sortable(false),
        ];
    }

    /**
     * @return array<int, TextColumn>
     */
    private static function processColumns(string $currency, LoanQueueService $queue): array
    {
        $coverage = $queue->processCoverage();

        return [
            TextColumn::make('queue_position')
                ->label(__('Queue #'))
                ->placeholder('—')
                ->sortable(),
            MemberTableColumns::relationNumber(),
            TextColumn::make('member.name')
                ->label(__('Member'))
                ->searchable()
                ->wrap(),
            TextColumn::make('fundTier.label')
                ->label(__('Fund tier'))
                ->placeholder('—'),
            TextColumn::make('amount_requested')
                ->label(__('Requested'))
                ->money($currency)
                ->sortable()
                ->summarize(self::moneySum($currency, __('Requested'))),
            TextColumn::make('amount_approved')
                ->label(__('Approved'))
                ->money($currency)
                ->placeholder('—')
                ->summarize(self::moneySum($currency, __('Approved'))),
            TextColumn::make('remaining_to_disburse')
                ->label(__('Remaining'))
                ->state(fn (Loan $record): float => $record->remainingToDisburse())
                ->money($currency)
                ->searchable(false)
                ->sortable(query: self::remainingToDisburseSortQuery())
                ->summarize([
                    LoanRemainingToDisburseSum::make()
                        ->label(fn(): string => __('Remaining'))
                        ->formatStateUsing(
                            fn($state): ?string => MoneyDisplay::tableSummaryHtml($state, $currency)
                        )
                        ->html(),
                ]),
            TextColumn::make('coverage')
                ->label(__('Coverage'))
                ->state(function (Loan $record) use ($coverage, $currency): string {
                    $entry = $coverage[(int) $record->id] ?? null;

                    if ($entry === null) {
                        return __('Waiting on pool');
                    }

                    return $entry['full']
                        ? __('Full')
                        : __('Partial up to :amount', [
                            'amount' => MoneyDisplay::format($entry['amount'], $currency) ?? number_format($entry['amount'], 2),
                        ]);
                })
                ->badge()
                ->color(function (Loan $record) use ($coverage): string {
                    $entry = $coverage[(int) $record->id] ?? null;

                    return match (true) {
                        $entry === null => 'gray',
                        $entry['full'] => 'success',
                        default => 'warning',
                    };
                })
                ->searchable(false)
                ->sortable(false),
            TextColumn::make('is_emergency')
                ->label(__('Lane'))
                ->badge()
                ->formatStateUsing(fn (bool $state): string => $state ? __('Emergency') : __('Standard'))
                ->color(fn (bool $state): string => $state ? 'danger' : 'gray'),
            TextColumn::make('status')
                ->badge()
                ->formatStateUsing(fn (string $state): string => Loan::statusOptions()[$state] ?? $state)
                ->color(fn (string $state): string => Loan::statusColor($state)),
        ];
    }

    /**
     * @return array<int, TextColumn>
     */
    private static function completedColumns(string $currency): array
    {
        return [
            TextColumn::make('settled_at')
                ->label(__('Completed'))
                ->dateTime()
                ->sortable(),
            TextColumn::make('status')
                ->label(__('Settlement'))
                ->badge()
                ->formatStateUsing(fn(string $state): string => Loan::statusOptions()[$state] ?? $state)
                ->color(fn(string $state): string => Loan::statusColor($state)),
            MemberTableColumns::relationNumber(),
            TextColumn::make('member.name')
                ->label(__('Member'))
                ->searchable()
                ->wrap(),
            TextColumn::make('amount_approved')
                ->label(__('Approved'))
                ->money($currency)
                ->placeholder('—')
                ->summarize(self::moneySum($currency, __('Approved'))),
            TextColumn::make('amount_disbursed')
                ->label(__('Disbursed'))
                ->money($currency)
                ->summarize(self::moneySum($currency, __('Disbursed'))),
            TextColumn::make('fund_tier_label')
                ->label(__('Fund tier'))
                ->state(function (Loan $record): string {
                    $tier = $record->relationLoaded('fundTier')
                        ? $record->fundTier
                        : ($record->fund_tier_id !== null
                            ? FundTier::withTrashed()->find($record->fund_tier_id)
                            : null);

                    if ($tier !== null) {
                        return $tier->label;
                    }

                    return FundTier::resolveForLoan($record)?->label ?? '—';
                })
                ->placeholder('—')
                ->badge()
                ->searchable(false)
                ->sortable(query: function (Builder $query, string $direction): Builder {
                    $dir = strtolower($direction) === 'desc' ? 'desc' : 'asc';

                    if (!self::queryHasFundTiersJoin($query)) {
                        $query->leftJoin('fund_tiers', 'fund_tiers.id', '=', 'loans.fund_tier_id');
                    }

                    return $query
                        ->orderBy('fund_tiers.label', $dir)
                        ->orderBy('loans.id', $dir);
                }),
            TextColumn::make('loanTier.label')
                ->label(__('Loan tier'))
                ->placeholder('—'),
            TextColumn::make('is_emergency')
                ->label(__('Lane'))
                ->badge()
                ->formatStateUsing(fn(bool $state): string => $state ? __('Emergency') : __('Standard'))
                ->color(fn(bool $state): string => $state ? 'danger' : 'gray'),
            TextColumn::make('disbursed_at')
                ->label(__('Disbursed at'))
                ->dateTime()
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('applied_at')
                ->label(__('Applied'))
                ->dateTime()
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    /**
     * @return \Closure(Builder, string): Builder
     */
    private static function remainingToDisburseSortQuery(): \Closure
    {
        return function (Builder $query, string $direction): Builder {
            $dir = strtolower($direction) === 'desc' ? 'desc' : 'asc';
            $table = $query->getModel()->getTable();

            return $query->orderByRaw(
                "GREATEST(0, COALESCE({$table}.amount_approved, 0) - COALESCE({$table}.amount_disbursed, 0)) {$dir}",
            );
        };
    }
}
