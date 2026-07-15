<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanQueueService;
use Filament\Actions\BulkActionGroup;
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
            default => $queue->intakeQuery(),
        };
    }

    public static function configure(Table $table, string $queueTab, LoanQueueService $queue): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');
        $projections = $queue->projections();

        $columns = $queueTab === 'process'
            ? self::processColumns($currency, $queue)
            : self::intakeColumns($currency);

        $columns[] = TextColumn::make('projected_wait')
            ->label(__('Projected approval'))
            ->state(fn (Loan $record): string => $projections->labelFor($record))
            ->badge()
            ->color(fn (Loan $record): string => $projections->projectionFor($record)['ready_now'] ? 'success' : 'info');

        $table = $queueTab === 'process'
            ? $table->defaultSort('queue_position')
            : $table->defaultSort(
                fn (Builder $query, string $direction): Builder => $query
                    ->orderByDesc('loans.is_emergency')
                    ->orderBy('loans.applied_at', $direction),
            );

        return TableGrouping::apply($table
            ->headerActions(LoanListTableHeaderActions::queue())
            ->columnManager(true)
            ->columns($columns)
            ->filters([
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
                    ->options(fn (): array => FundTier::query()
                        ->where('is_active', true)
                        ->orderBy('tier_number')
                        ->pluck('label', 'id')
                        ->all()),
                TernaryFilter::make('is_emergency')
                    ->label(__('Emergency')),
                DateColumnRangeFilter::make('applied_at', __('Applied')),
            ])
            ->recordActions(TableRecordActionGroups::wrap(LoanFilamentActions::queueTableActions($queueTab)))
            ->toolbarActions([
                BulkActionGroup::make(LoanFilamentActions::bulkActions()),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25), TableGrouping::loanQueue());
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
            TextColumn::make('loanTier.label')
                ->label(__('Loan tier'))
                ->placeholder('—'),
            TextColumn::make('expected_fund_tier')
                ->label(__('Fund tier'))
                ->state(fn (Loan $record): string => FundTier::resolveForLoan($record)?->label ?? '—')
                ->badge()
                ->color(fn (Loan $record): string => $record->is_emergency ? 'danger' : 'gray'),
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
            TextColumn::make('member.name')
                ->label(__('Member'))
                ->searchable()
                ->wrap(),
            TextColumn::make('fundTier.label')
                ->label(__('Fund tier'))
                ->placeholder('—'),
            TextColumn::make('amount_approved')
                ->label(__('Approved'))
                ->money($currency)
                ->placeholder('—'),
            TextColumn::make('remaining_to_disburse')
                ->label(__('Remaining'))
                ->state(fn (Loan $record): float => $record->remainingToDisburse())
                ->money($currency),
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
                }),
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
}
