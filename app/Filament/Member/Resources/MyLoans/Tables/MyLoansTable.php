<?php

namespace App\Filament\Member\Resources\MyLoans\Tables;

use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Filament\Support\DateColumnRangeFilter;
use App\Models\Tenant\Setting;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class MyLoansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('amount')
                    ->label('Loan amount')
                    ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                    ->sortable(),
                TextColumn::make('interest_rate')
                    ->suffix('%')
                    ->sortable(),
                TextColumn::make('term_months')
                    ->label('Term')
                    ->suffix(' months')
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
            ->recordUrl(fn (Model $record): string => MyLoanResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('applied_at', 'desc');
    }
}
