<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\Loan;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanQueuePriorityScoreService;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class LoanQueueTable
{
    public static function queueQuery(string $tab = 'needs_decision'): Builder
    {
        $query = Loan::query()
            ->with(['member', 'loanTier', 'fundTier'])
            ->select('loans.*');

        return match ($tab) {
            'ready_to_disburse' => $query
                ->whereIn('loans.status', ['approved', 'partially_disbursed'])
                ->whereRaw('COALESCE(loans.amount_disbursed, 0) < COALESCE(loans.amount_approved, loans.amount_requested, 0)'),
            default => $query->where('loans.status', 'pending'),
        };
    }

    public static function configure(Table $table, string $queueTab = 'needs_decision'): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');
        $priorityService = app(LoanQueuePriorityScoreService::class);

        $table = $queueTab === 'needs_decision'
            ? $table->defaultSort(
                fn (Builder $query, string $direction): Builder => $priorityService->applySort($query, $direction),
            )
            : $table->defaultSort('queue_position');

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
