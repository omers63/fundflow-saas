<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tables\Columns\CollectedEmiCashColumn;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanEmiCollectionCatalogService;
use App\Support\MemberNumberSettings;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

final class LoanEmiCollectionTables
{
    public static function configurePendingMembersTable(Table $table): Table
    {
        $catalog = app(LoanEmiCollectionCatalogService::class);
        [$month, $year] = LoanResource::resolveListCycle();
        $currency = Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply(
            $table
                ->query(fn () => $catalog->membersWithCollectableEmisQuery($month, $year))
                ->heading(__('To collect – EMIs through :period', [
                    'period' => $catalog->periodLabel($month, $year),
                ]))
                ->defaultSort(fn (Builder $query, string $direction): Builder => MemberNumberSettings::applySequenceOrder($query, $direction))
                ->headerActions([
                    LoanEmiCollectionHeaderActions::cycleCollectionGroup(),
                ])
                ->columns([
                    MemberTableColumns::number(label: __('Member #'))
                        ->searchable(),
                    MemberTableColumns::name(label: __('Member'))
                        ->searchable()
                        ->sortable()
                        ->wrap(),
                    TextColumn::make('pending_emis')
                        ->label(__('Pending EMIs'))
                        ->state(fn (Member $record): int => $catalog->pendingInstallmentCountForMember($record, $month, $year))
                        ->alignEnd()
                        ->searchable(false)
                        ->sortable(false),
                    TextColumn::make('total_due')
                        ->label(__('Total due'))
                        ->state(fn (Member $record): float => $catalog->requiredCashForMember($record, $month, $year))
                        ->money($currency)
                        ->alignEnd()
                        ->searchable(false)
                        ->sortable(false),
                    TextColumn::make('available_cash')
                        ->label(__('Cash balance'))
                        ->state(fn (Member $record): float => $record->getCashBalance())
                        ->money($currency)
                        ->color(fn (Member $record): string => $record->getCashBalance() < 0 ? 'danger' : 'gray')
                        ->alignEnd()
                        ->searchable(false)
                        ->sortable(false),
                    TextColumn::make('coverage')
                        ->label(__('Ready'))
                        ->state(function (Member $record) use ($catalog, $month, $year): string {
                            return $catalog->memberHasSufficientCash($record, $month, $year)
                                ? __('Yes')
                                : __('Insufficient');
                        })
                        ->badge()
                        ->color(fn (string $state): string => $state === __('Yes') ? 'success' : 'warning')
                        ->searchable(false)
                        ->sortable(false),
                    TextColumn::make('parent.name')
                        ->label(__('Parent'))
                        ->placeholder(__('—'))
                        ->toggleable(),
                ])
                ->recordAction(null)
                ->recordUrl(fn (Member $record): ?string => self::collectLoanViewUrl($catalog, $record, $month, $year))
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
                                    if (! $record instanceof Member) {
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
        [$month, $year] = LoanResource::resolveListCycle();
        $currency = Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply(
            $table
                ->query(fn () => $catalog->collectedInstallmentsQuery($month, $year))
                ->heading(__('Collected – EMIs due through :period', [
                    'period' => $catalog->periodLabel($month, $year),
                ]))
                ->columns([
                    TextColumn::make('loan.member.member_number')
                        ->label(__('Member #'))
                        ->sortable(query: fn (Builder $query, string $direction): Builder => MemberNumberSettings::applyOrderByLoanInstallmentMember($query, $direction))
                        ->url(fn (LoanInstallment $record): ?string => MemberTableColumns::resolveMemberUrl(
                            'loan.member.name',
                            $record,
                        )),
                    TextColumn::make('loan.member.name')
                        ->label(__('Member'))
                        ->wrap()
                        ->sortable(query: fn (Builder $query, string $direction): Builder => self::sortCollectedByMemberName($query, $direction))
                        ->url(fn (LoanInstallment $record): ?string => MemberTableColumns::resolveMemberUrl(
                            'loan.member.name',
                            $record,
                        )),
                    TextColumn::make('loan_id')
                        ->label(__('Loan'))
                        ->formatStateUsing(fn (int $state): string => '#'.$state)
                        ->sortable()
                        ->url(fn (LoanInstallment $record): ?string => self::collectedLoanViewUrl($record)),
                    TextColumn::make('installment_number')
                        ->label(__('#'))
                        ->sortable(),
                    TextColumn::make('due_date')
                        ->date()
                        ->sortable(),
                    CollectedEmiCashColumn::make()
                        ->label(__('Amount')),
                    TextColumn::make('late_fee_amount')
                        ->label(__('Late fee'))
                        ->money($currency)
                        ->placeholder(__('—')),
                    TextColumn::make('paid_at')
                        ->label(__('Paid on'))
                        ->dateTime()
                        ->sortable(),
                ])
                ->recordAction(null)
                ->recordUrl(fn (LoanInstallment $record): ?string => self::collectedLoanViewUrl($record))
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

    private static function collectLoanViewUrl(
        LoanEmiCollectionCatalogService $catalog,
        Member $member,
        int $month,
        int $year,
    ): ?string {
        $loanId = $catalog->primaryCollectableLoanIdForMember($member, $month, $year);

        return filled($loanId)
            ? LoanResource::getUrl('view', ['record' => $loanId])
            : null;
    }

    private static function collectedLoanViewUrl(LoanInstallment $installment): ?string
    {
        return filled($installment->loan_id)
            ? LoanResource::getUrl('view', ['record' => $installment->loan_id])
            : null;
    }

    private static function sortCollectedByMemberName(Builder $query, string $direction): Builder
    {
        return $query->orderBy(
            Member::query()
                ->select('members.name')
                ->join('loans', 'loans.member_id', '=', 'members.id')
                ->whereColumn('loans.id', 'loan_installments.loan_id')
                ->limit(1),
            $direction,
        );
    }
}
