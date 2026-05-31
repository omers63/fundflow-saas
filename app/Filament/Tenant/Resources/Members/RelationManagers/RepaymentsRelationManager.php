<?php

namespace App\Filament\Tenant\Resources\Members\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\LateSettledArrearsTableStyling;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Setting;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RepaymentsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'paidLoanInstallments';

    protected static ?string $title = 'Repayments';

    public function table(Table $table): Table
    {
        $currency = fn (): string => Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply(
            $table
                ->recordTitleAttribute('paid_at')
                ->columns([
                    TextColumn::make('loan_id')
                        ->label(__('Loan'))
                        ->formatStateUsing(fn (int $state): string => '#'.$state)
                        ->url(fn (LoanInstallment $record): string => LoanResource::getUrl('edit', ['record' => $record->loan_id])),
                    TextColumn::make('installment_number')
                        ->label(__('#'))
                        ->sortable(),
                    TextColumn::make('due_date')
                        ->label(__('Due'))
                        ->date()
                        ->sortable(),
                    TextColumn::make('amount')
                        ->money($currency)
                        ->sortable(),
                    TextColumn::make('late_fee_amount')
                        ->label(__('Late fee'))
                        ->money($currency)
                        ->placeholder(__('—')),
                    TextColumn::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state, LoanInstallment $record): string => LateSettledArrearsTableStyling::installmentStatusLabel($record))
                        ->color(fn (string $state, LoanInstallment $record): string => LateSettledArrearsTableStyling::installmentStatusColor($record))
                        ->tooltip(fn (LoanInstallment $record): ?string => LateSettledArrearsTableStyling::installmentWasSettledLate($record)
                            ? LateSettledArrearsTableStyling::eligibilityHint()
                            : null),
                    TextColumn::make('paid_at')
                        ->label(__('Paid on'))
                        ->dateTime()
                        ->sortable(),
                ])
                ->filters([
                    SelectFilter::make('loan_id')
                        ->label('Loan')
                        ->options(function (): array {
                            $currency = Setting::get('general', 'currency', 'USD');

                            return $this->getOwnerRecord()
                                ->loans()
                                ->orderBy('applied_at', 'desc')
                                ->get()
                                ->mapWithKeys(function (Loan $loan) use ($currency): array {
                                    $amount = number_format((float) $loan->amount, 2).' '.$currency;

                                    return [$loan->id => $amount.' · '.$loan->applied_at->format('M j, Y')];
                                })
                                ->all();
                        }),
                    DateColumnRangeFilter::make('paid_at', 'Paid on'),
                ])
                ->recordClasses(fn (LoanInstallment $record): ?string => LateSettledArrearsTableStyling::installmentRecordClasses($record))
                ->recordActions(TableRecordActionGroups::wrap([
                    Action::make('viewLoan')
                        ->label(__('View loan'))
                        ->icon('heroicon-o-banknotes')
                        ->url(fn (LoanInstallment $record): string => LoanResource::getUrl('edit', ['record' => $record->loan_id])),
                ]))
                ->toolbarActions([
                    BulkActionGroup::make([
                        TableToolbar::refreshBulkAction(),
                    ]),
                ])
                ->defaultSort('paid_at', 'desc'),
            TableGrouping::loanInstallments(includeLoanMember: false)
        );
    }
}
