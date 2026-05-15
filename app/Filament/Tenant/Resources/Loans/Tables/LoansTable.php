<?php

namespace App\Filament\Tenant\Resources\Loans\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Models\Tenant\Setting;
use App\Services\LoanService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LoansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('member.name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('amount')
                    ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                    ->sortable(),
                TextColumn::make('interest_rate')
                    ->suffix('%')
                    ->sortable(),
                TextColumn::make('term_months')
                    ->label('Term')
                    ->suffix(' mo')
                    ->sortable(),
                TextColumn::make('monthly_repayment')
                    ->money(fn (): string => Setting::get('general', 'currency', 'USD')),
                TextColumn::make('total_repaid')
                    ->money(fn (): string => Setting::get('general', 'currency', 'USD')),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'approved' => 'info',
                        'disbursed', 'repaying' => 'primary',
                        'completed' => 'success',
                        'defaulted' => 'danger',
                    }),
                TextColumn::make('applied_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'disbursed' => 'Disbursed',
                        'repaying' => 'Repaying',
                        'completed' => 'Completed',
                        'defaulted' => 'Defaulted',
                    ]),
                DateColumnRangeFilter::make('applied_at', 'Applied'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->hidden(fn ($record) => $record->status !== 'pending')
                    ->action(function ($record, LoanService $service) {
                        $service->approveLoan($record);
                        Notification::make()->title(__('Loan approved'))->success()->send();
                    }),
                Action::make('disburse')
                    ->label('Disburse')
                    ->icon('heroicon-o-banknotes')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalDescription(__('This will debit Master Fund, debit Member Fund, and credit Member Cash.'))
                    ->hidden(fn ($record) => $record->status !== 'approved')
                    ->action(function ($record, LoanService $service) {
                        $service->disburseLoan($record);
                        Notification::make()->title(__('Loan disbursed'))->success()->send();
                    }),
                Action::make('payout')
                    ->label('Payout')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(__('This will debit Member Cash and Master Cash, reflecting the actual bank transfer to the member.'))
                    ->hidden(fn ($record) => $record->status !== 'disbursed')
                    ->action(function ($record, LoanService $service) {
                        $service->payoutLoan($record);
                        Notification::make()->title(__('Loan paid out to member'))->success()->send();
                    }),
            ])
            ->defaultSort('applied_at', 'desc');
    }
}
