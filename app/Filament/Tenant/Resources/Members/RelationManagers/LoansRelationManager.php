<?php

namespace App\Filament\Tenant\Resources\Members\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\LoanOutstandingColumn;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LoansRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'loans';

    protected static ?string $title = 'Loans';

    public function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    public function table(Table $table): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply(
            $table
                ->columnManager(true)
                ->recordTitleAttribute('id')
                ->defaultSort('applied_at', 'desc')
                ->columns([
                    TextColumn::make('id')
                        ->label(__('Loan #')),
                    TextColumn::make('amount')
                        ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                        ->sortable(),
                    TextColumn::make('interest_rate')
                        ->label(__('Interest'))
                        ->suffix('%'),
                    TextColumn::make('term_months')
                        ->label(__('Term'))
                        ->suffix(' '.__('mo')),
                    TextColumn::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => __(ucfirst($state)))
                        ->color(fn (string $state): string => match ($state) {
                            'pending' => 'warning',
                            'approved' => 'info',
                            'disbursed', 'repaying' => 'primary',
                            'completed' => 'success',
                            'defaulted' => 'danger',
                            default => 'gray',
                        }),
                    LoanOutstandingColumn::make($currency),
                    TextColumn::make('applied_at')
                        ->label(__('Applied'))
                        ->dateTime()
                        ->sortable(),
                ])
                ->filters([
                    SelectFilter::make('status')
                        ->options([
                            'pending' => __('Pending'),
                            'approved' => __('Approved'),
                            'disbursed' => __('Disbursed'),
                            'repaying' => __('Repaying'),
                            'completed' => __('Completed'),
                            'defaulted' => __('Defaulted'),
                        ]),
                    DateColumnRangeFilter::make('applied_at', __('Applied')),
                ])
                ->headerActions([
                    Action::make('new_loan')
                        ->label(__('New loan'))
                        ->icon('heroicon-o-plus-circle')
                        ->url(fn (): string => LoanResource::getUrl('create').'?member_id='.$this->getOwnerRecord()->getKey())
                        ->visible(fn (): bool => LoanResource::canCreate()
                            && $this->getOwnerRecord() instanceof Member
                            && $this->getOwnerRecord()->isEligibleForLoan()),
                ])
                ->recordActions(TableRecordActionGroups::wrap([
                    EditAction::make()
                        ->url(fn (Loan $record): string => LoanResource::getUrl('edit', ['record' => $record])),
                ]))
                ->toolbarActions([
                    BulkActionGroup::make([
                        TableToolbar::refreshBulkAction(),
                    ]),
                ]),
            TableGrouping::loans(includeMember: false)
        );
    }
}
