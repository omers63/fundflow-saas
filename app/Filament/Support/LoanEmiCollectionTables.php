<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanEmiCollectionCatalogService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

final class LoanEmiCollectionTables
{
    public static function configurePendingMembersTable(Table $table): Table
    {
        $catalog = app(LoanEmiCollectionCatalogService::class);
        [$month, $year] = $catalog->currentOpenPeriod();
        $currency = Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply(
            $table
                ->query(fn() => $catalog->membersWithCollectableEmisQuery($month, $year))
                ->heading(__('To collect – EMIs through :period', [
                    'period' => $catalog->periodLabel($month, $year),
                ]))
                ->columns([
                    MemberTableColumns::number(label: __('Member #'))
                        ->searchable()
                        ->sortable(),
                    MemberTableColumns::name(label: __('Member'))
                        ->searchable()
                        ->sortable()
                        ->wrap(),
                    TextColumn::make('pending_emis')
                        ->label(__('Pending EMIs'))
                        ->state(fn(Member $record): int => $catalog->pendingInstallmentCountForMember($record, $month, $year))
                        ->alignEnd()
                        ->sortable(false),
                    TextColumn::make('total_due')
                        ->label(__('Total due'))
                        ->state(fn(Member $record): float => $catalog->requiredCashForMember($record, $month, $year))
                        ->money($currency)
                        ->alignEnd()
                        ->sortable(false),
                    TextColumn::make('available_cash')
                        ->label(__('Cash balance'))
                        ->state(fn(Member $record): float => $record->getCashBalance())
                        ->money($currency)
                        ->alignEnd()
                        ->sortable(false),
                    TextColumn::make('coverage')
                        ->label(__('Ready'))
                        ->state(function (Member $record) use ($catalog, $month, $year): string {
                            return $catalog->memberHasSufficientCash($record, $month, $year)
                                ? __('Yes')
                                : __('Insufficient');
                        })
                        ->badge()
                        ->color(fn(string $state): string => $state === __('Yes') ? 'success' : 'warning')
                        ->sortable(false),
                    TextColumn::make('parent.name')
                        ->label(__('Parent'))
                        ->placeholder(__('—'))
                        ->toggleable(),
                ])
                ->recordActions(TableRecordActionGroups::wrap([
                    Action::make('apply_single')
                        ->label(__('Apply now'))
                        ->icon('heroicon-o-check')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (Member $record, Action $action, Component $livewire) use ($catalog, $month, $year): void {
                            $outcome = $catalog->applyForMember($record, $month, $year);

                            LoanEmiCollectionTableActions::notifyApplyOutcome($outcome, $record->name, $action);

                            if (in_array($outcome, ['collected', 'partial'], true)) {
                                LoanEmiCollectionTableActions::refreshViews($livewire);
                            }
                        }),
                ]))
                ->toolbarActions([
                    BulkActionGroup::make([
                        BulkAction::make('applySelected')
                            ->label(__('Apply now'))
                            ->icon('heroicon-o-check')
                            ->color('success')
                            ->requiresConfirmation()
                            ->action(function (Collection $records, Component $livewire) use ($catalog, $month, $year): void {
                                $collected = 0;
                                $partial = 0;
                                $skipped = 0;

                                foreach ($records as $record) {
                                    if (!$record instanceof Member) {
                                        continue;
                                    }

                                    $outcome = $catalog->applyForMember($record, $month, $year);

                                    match ($outcome) {
                                        'collected' => $collected++,
                                        'partial' => $partial++,
                                        default => $skipped++,
                                    };
                                }

                                Notification::make()
                                    ->title(__('Bulk EMI collection complete'))
                                    ->body(__(':collected collected, :partial partial, :skipped skipped or no cash.', [
                                        'collected' => $collected,
                                        'partial' => $partial,
                                        'skipped' => $skipped,
                                    ]))
                                    ->color($collected > 0 ? 'success' : 'warning')
                                    ->send();

                                if ($collected > 0 || $partial > 0) {
                                    LoanEmiCollectionTableActions::refreshViews($livewire);
                                }
                            }),
                        TableToolbar::refreshBulkAction(),
                    ]),
                ]),
            TableGrouping::members(),
        );
    }

    public static function configureCollectedTable(Table $table): Table
    {
        $catalog = app(LoanEmiCollectionCatalogService::class);
        [$month, $year] = $catalog->currentOpenPeriod();
        $currency = Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply(
            $table
                ->query(fn() => $catalog->collectedInstallmentsQuery($month, $year))
                ->heading(__('Collected – EMIs due through :period', [
                    'period' => $catalog->periodLabel($month, $year),
                ]))
                ->columns([
                    TextColumn::make('loan.member.member_number')
                        ->label(__('Member #'))
                        ->url(fn(LoanInstallment $record): ?string => MemberTableColumns::resolveMemberUrl(
                            'loan.member.name',
                            $record,
                        )),
                    TextColumn::make('loan.member.name')
                        ->label(__('Member'))
                        ->wrap()
                        ->url(fn(LoanInstallment $record): ?string => MemberTableColumns::resolveMemberUrl(
                            'loan.member.name',
                            $record,
                        )),
                    TextColumn::make('loan_id')
                        ->label(__('Loan'))
                        ->formatStateUsing(fn(int $state): string => '#' . $state),
                    TextColumn::make('installment_number')
                        ->label(__('#'))
                        ->sortable(),
                    TextColumn::make('due_date')
                        ->date()
                        ->sortable(),
                    TextColumn::make('amount')
                        ->money($currency),
                    TextColumn::make('late_fee_amount')
                        ->label(__('Late fee'))
                        ->money($currency)
                        ->placeholder(__('—')),
                    TextColumn::make('paid_at')
                        ->label(__('Paid on'))
                        ->dateTime()
                        ->sortable(),
                ])
                ->recordActions(TableRecordActionGroups::wrap([]))
                ->toolbarActions([
                    BulkActionGroup::make([
                        TableToolbar::refreshBulkAction(),
                    ]),
                ])
                ->defaultSort('paid_at', 'desc'),
            TableGrouping::loanInstallments(includeLoanMember: true),
        );
    }
}
