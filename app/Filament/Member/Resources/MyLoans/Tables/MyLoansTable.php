<?php

namespace App\Filament\Member\Resources\MyLoans\Tables;

use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\LoanFilamentActions;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Setting;
use App\Support\Loans\LoanUserFacingStage;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class MyLoansTable
{
    public static function configure(Table $table): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply(
            $table
                ->columnManager(true)
                ->columns([
                    TextColumn::make('amount_requested')
                        ->label(__('Amount'))
                        ->money($currency)
                        ->sortable(),
                    TextColumn::make('outstanding')
                        ->label(__('Outstanding'))
                        ->state(fn (Loan $record): float => $record->getOutstandingBalance())
                        ->money($currency),
                    TextColumn::make('installments_count')
                        ->label(__('Installments'))
                        ->placeholder(__('—')),
                    TextColumn::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state, Loan $record): string => LoanUserFacingStage::memberListStatusLabel($record))
                        ->color(fn (string $state): string => Loan::statusColor($state)),
                    TextColumn::make('applied_at')
                        ->label(__('Applied'))
                        ->dateTime()
                        ->sortable(),
                ])
                ->filters([
                    SelectFilter::make('status')
                        ->options(Loan::statusOptions()),
                    DateColumnRangeFilter::make('applied_at', __('Applied')),
                ])
                ->recordUrl(fn (Model $record): string => MyLoanResource::getUrl('view', ['record' => $record]))
                ->recordActions(TableRecordActionGroups::wrap([
                    ViewAction::make(),
                    LoanFilamentActions::cancel(),
                ]))
                ->toolbarActions([
                    BulkActionGroup::make([
                        TableToolbar::refreshBulkAction(),
                    ]),
                ])
                ->defaultSort('applied_at', 'desc'),
            TableGrouping::loans(includeMember: false)
        );
    }
}
