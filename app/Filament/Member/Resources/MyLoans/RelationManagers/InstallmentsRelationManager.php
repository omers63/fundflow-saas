<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyLoans\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\LateSettledArrearsTableStyling;
use App\Filament\Support\LoanInstallmentTableColumns;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Setting;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InstallmentsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'installments';

    protected static ?string $title = 'Repayment schedule';

    public function table(Table $table): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply($table
            ->columnManager(true)
            ->columns([
                TextColumn::make('installment_number')
                    ->label(__('#'))
                    ->sortable(),
                LoanInstallmentTableColumns::cycle(),
                TextColumn::make('due_date')
                    ->label(__('Due'))
                    ->date()
                    ->sortable(),
                TextColumn::make('amount')
                    ->money($currency),
                TextColumn::make('late_fee_amount')
                    ->label(__('Late fee'))
                    ->money($currency)
                    ->placeholder(__('—')),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn(string $state, LoanInstallment $record): string => LateSettledArrearsTableStyling::installmentStatusLabel($record))
                    ->color(fn(string $state, LoanInstallment $record): string => LateSettledArrearsTableStyling::installmentStatusColor($record))
                    ->tooltip(fn(LoanInstallment $record): ?string => LateSettledArrearsTableStyling::installmentWasSettledLate($record)
                        ? LateSettledArrearsTableStyling::eligibilityHint()
                        : null),
                TextColumn::make('paid_at')
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
            ->recordClasses(fn(LoanInstallment $record): ?string => LateSettledArrearsTableStyling::installmentRecordClasses($record))
            ->recordActions(TableRecordActionGroups::wrap([]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ])
            ->emptyStateHeading(__('No installments'))
            ->emptyStateDescription(__('Installments are created when the loan is fully disbursed.')), TableGrouping::loanInstallments());
    }
}
