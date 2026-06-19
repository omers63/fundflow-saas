<?php

declare(strict_types=1);

namespace App\Filament\Member\Widgets;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\LateSettledArrearsTableStyling;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Setting;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class MemberLoanInstallmentsTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public ?int $loanId = null;

    public function getHeading(): ?string
    {
        return __('Repayment schedule');
    }

    protected function getTableQuery(): Builder
    {
        if ($this->loanId === null) {
            return LoanInstallment::query()->whereRaw('1 = 0');
        }

        return LoanInstallment::query()->where('loan_id', $this->loanId);
    }

    public function table(Table $table): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply($table
            ->heading(__('Repayment schedule'))
            ->description(__('Installment due dates, status, and collected amounts.'))
            ->columns([
                TextColumn::make('installment_number')
                    ->label(__('#'))
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label(__('Due'))
                    ->date()
                    ->sortable(),
                TextColumn::make('amount')
                    ->money($currency),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state, LoanInstallment $record): string => LateSettledArrearsTableStyling::installmentStatusLabel($record))
                    ->color(fn (string $state, LoanInstallment $record): string => LateSettledArrearsTableStyling::installmentStatusColor($record)),
                TextColumn::make('paid_at')
                    ->label(__('Collected'))
                    ->dateTime()
                    ->placeholder(__('—')),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => __('Pending'),
                        'paid' => __('Paid'),
                        'overdue' => __('Overdue'),
                    ]),
                DateColumnRangeFilter::make('due_date', __('Due date')),
            ])
            ->defaultSort('installment_number')
            ->recordClasses(fn (LoanInstallment $record): ?string => LateSettledArrearsTableStyling::installmentRecordClasses($record))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ])
            ->emptyStateHeading(__('No installments'))
            ->emptyStateDescription(__('Installments are created when the loan is fully disbursed.')), TableGrouping::loanInstallments());
    }
}
