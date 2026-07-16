<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\Loans\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\LoanFilamentActions;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanRepaymentLogService;
use App\Support\LoanRepaymentNote;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class RepaymentsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'repayments';

    protected static ?string $title = 'Actual Repayments';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        if (! $ownerRecord instanceof Loan) {
            return false;
        }

        app(LoanRepaymentLogService::class)->backfillSettlementRepaymentIfMissing($ownerRecord);

        return $ownerRecord->repayments()->exists();
    }

    public function table(Table $table): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply($table
            ->columnManager(false)
            ->columns([
                TextColumn::make('paid_at')
                    ->label(__('Paid at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('amount')
                    ->money($currency)
                    ->sortable(),
                TextColumn::make('repayment_type')
                    ->label(__('Type'))
                    ->state(fn (LoanRepayment $record): string => LoanRepaymentNote::label($record->notes))
                    ->badge()
                    ->color(fn (LoanRepayment $record): string => LoanRepaymentNote::isSettlement($record->notes) ? 'success' : 'gray'),
                TextColumn::make('notes')
                    ->label(__('Notes'))
                    ->formatStateUsing(fn (?string $state): ?string => LoanRepaymentNote::isSettlement($state) || str_starts_with((string) $state, LoanRepaymentNote::PREFIX)
                        ? null
                        : $state)
                    ->placeholder(__('—'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                DateColumnRangeFilter::make('paid_at', __('Paid at')),
            ])
            ->defaultSort('paid_at', 'desc')
            ->headerActions([
                LoanFilamentActions::earlySettleForOwner(fn (): Loan => $this->getOwnerRecord()),
            ])
            ->recordActions(TableRecordActionGroups::wrap([]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ])
            ->emptyStateHeading(__('No repayments posted yet'))
            ->emptyStateDescription(__('Settlements appear as one line each; EMI repayments are logged individually.')), TableGrouping::loanRepayments());
    }
}
