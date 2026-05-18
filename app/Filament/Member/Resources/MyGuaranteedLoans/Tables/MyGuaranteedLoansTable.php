<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyGuaranteedLoans\Tables;

use App\Filament\Member\Resources\MyGuaranteedLoans\MyGuaranteedLoanResource;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Setting;
use App\Support\Loans\LoanUserFacingStage;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class MyGuaranteedLoansTable
{
    public static function configure(Table $table): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return $table
            ->columns([
                TextColumn::make('member.name')
                    ->label(__('Borrower'))
                    ->searchable(),
                TextColumn::make('amount_requested')
                    ->label(__('Amount'))
                    ->money($currency),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state, Loan $record): string => LoanUserFacingStage::memberListStatusLabel($record))
                    ->color(fn (string $state): string => Loan::statusColor($state)),
                TextColumn::make('guarantor_liability_transferred_at')
                    ->label(__('Liability'))
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state ? __('On guarantor') : __('On borrower'))
                    ->color(fn ($state): string => $state ? 'warning' : 'gray'),
                TextColumn::make('applied_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordUrl(fn (Model $record): string => MyGuaranteedLoanResource::getUrl('view', ['record' => $record]))
            ->recordActions(TableRecordActionGroups::wrap([
                ViewAction::make(),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ])
            ->defaultSort('applied_at', 'desc');
    }
}
