<?php

namespace App\Filament\Tenant\Resources\Members\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Setting;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RepaymentsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'repayments';

    protected static ?string $title = 'Repayments';

    public function table(Table $table): Table
    {
        return TableGrouping::apply($table
            ->recordTitleAttribute('paid_at')
            ->columns([
                TextColumn::make('loan.amount')
                    ->label('Loan')
                    ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                    ->sortable(),
                TextColumn::make('amount')
                    ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                    ->sortable(),
                TextColumn::make('paid_at')
                    ->label('Paid on')
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
            ->recordActions(TableRecordActionGroups::wrap([
                Action::make('viewLoan')
                    ->label(__('View loan'))
                    ->icon('heroicon-o-banknotes')
                    ->url(fn (LoanRepayment $record): string => LoanResource::getUrl('edit', ['record' => $record->loan_id])),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ])
            ->defaultSort('paid_at', 'desc'),
            TableGrouping::loanRepayments());
    }
}
