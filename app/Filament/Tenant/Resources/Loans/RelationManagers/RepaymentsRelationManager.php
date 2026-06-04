<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\Loans\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Support\ActionModalFailure;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\Setting;
use App\Services\LoanService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RepaymentsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'repayments';

    protected static ?string $title = 'Legacy repayments';

    public function table(Table $table): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply($table
            ->columnManager(true)
            ->columns([
                TextColumn::make('paid_at')
                    ->label(__('Paid at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('amount')
                    ->money($currency)
                    ->sortable(),
                TextColumn::make('notes')
                    ->placeholder(__('—'))
                    ->wrap(),
            ])
            ->filters([
                DateColumnRangeFilter::make('paid_at', __('Paid at')),
            ])
            ->defaultSort('paid_at', 'desc')
            ->headerActions([
                Action::make('earlySettle')
                    ->label(__('Early settle'))
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (): bool => $this->getOwnerRecord()->status === 'active')
                    ->requiresConfirmation()
                    ->action(function (Action $action, LoanService $service): void {
                        if (! ActionModalFailure::attemptThrowable(
                            $action,
                            fn () => $service->earlySettle($this->getOwnerRecord()),
                            __('Settlement failed'),
                        )) {
                            return;
                        }

                        Notification::make()->title(__('Loan early settled'))->success()->send();
                    }),
            ])
            ->recordActions(TableRecordActionGroups::wrap([]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ])
            ->emptyStateHeading(__('No manual repayment rows'))
            ->emptyStateDescription(__('Scheduled repayments post via installments when marked paid.')), TableGrouping::loanRepayments());
    }
}
