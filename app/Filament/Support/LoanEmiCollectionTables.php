<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tables\Columns\CollectedEmiCashColumn;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Models\Tenant\Loan;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

final class LoanEmiCollectionTables
{
    public static function configurePendingMembersTable(
        Table $table,
        ?string $heading = null,
        bool $includeLoanNumber = true,
        bool $includeCollectionFilters = true,
    ): Table {
        $catalog = app(LoanEmiCollectionCatalogService::class);
        [$month, $year] = LoanResource::resolveListCycle();
        $currency = Setting::get('general', 'currency', 'USD');

        $columns = [
            MemberTableColumns::number(label: __('Member #'))
                ->searchable(),
            MemberTableColumns::name(label: __('Member'))
                ->searchable()
                ->sortable()
                ->wrap(),
        ];

        if ($includeLoanNumber) {
            $columns[] = TextColumn::make('loan_number')
                ->label(__('Loan #'))
                ->state(fn (Member $record): ?int => $catalog->primaryCollectableLoanIdForMember($record, $month, $year))
                ->formatStateUsing(fn (?int $state): string => filled($state) ? '#'.$state : __('—'))
                ->url(fn (Member $record): ?string => self::collectLoanViewUrl($catalog, $record, $month, $year))
                ->searchable(false)
                ->sortable(false);
        }

        $columns = [
            ...$columns,
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
            TextColumn::make('loan_outstanding')
                ->label(__('Loan outstanding'))
                ->state(fn (Member $record): float => $catalog->outstandingLoanBalanceForMember($record, $month, $year))
                ->money($currency)
                ->alignEnd()
                ->searchable(false)
                ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByLoanOutstanding($direction)),
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
        ];

        return TableGrouping::apply(
            $table
                ->query(fn () => $catalog->membersWithCollectableEmisQuery($month, $year))
                ->heading($heading ?? __('To collect – :period', [
                    'period' => $catalog->periodLabel($month, $year),
                ]))
                ->defaultSort(fn (Builder $query, string $direction): Builder => MemberNumberSettings::applySequenceOrder($query, $direction))
                ->headerActions([
                    LoanEmiCollectionHeaderActions::cycleCollectionGroup(),
                ])
                ->columns($columns)
                ->filters($includeCollectionFilters ? self::collectionMemberFilters($catalog, $month, $year) : [])
                ->recordAction(null)
                ->recordUrl(fn (Member $record): ?string => self::collectLoanViewUrl($catalog, $record, $month, $year))
                ->recordActions(TableRecordActionGroups::wrap([
                    Action::make('apply_single')
                        ->label(__('Apply now'))
                        ->icon('heroicon-o-check')
                        ->color('success')
                        ->schema([
                            ContributionCycleHeaderActions::collectOldestArrearsFirstToggle(),
                        ])
                        ->fillForm([
                            'collect_oldest_arrears_first' => true,
                        ])
                        ->action(function (Member $record, array $data, Action $action, Component $livewire) use ($catalog, $month, $year): void {
                            $outcome = $catalog->applyForMember(
                                $record,
                                $month,
                                $year,
                                (bool) ($data['collect_oldest_arrears_first'] ?? true),
                            );

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
                            ->schema([
                                ContributionCycleHeaderActions::collectOldestArrearsFirstToggle(),
                            ])
                            ->fillForm([
                                'collect_oldest_arrears_first' => true,
                            ])
                            ->action(function (Collection $records, array $data, Component $livewire) use ($catalog, $month, $year): void {
                                $collected = 0;
                                $partial = 0;
                                $skipped = 0;
                                $collectOldestArrearsFirst = (bool) ($data['collect_oldest_arrears_first'] ?? true);

                                foreach ($records as $record) {
                                    if (! $record instanceof Member) {
                                        continue;
                                    }

                                    $outcome = $catalog->applyForMember(
                                        $record,
                                        $month,
                                        $year,
                                        $collectOldestArrearsFirst,
                                    );

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

    /**
     * @return array<int, SelectFilter|TernaryFilter>
     */
    private static function collectionMemberFilters(
        LoanEmiCollectionCatalogService $catalog,
        int $month,
        int $year,
    ): array {
        return [
            MemberSelect::configureFilter(
                SelectFilter::make('member_id')->label(__('Member')),
                activeOnly: true,
            )->query(function (Builder $query, array $data): Builder {
                if (blank($data['value'] ?? null)) {
                    return $query;
                }

                return $query->where('members.id', (int) $data['value']);
            }),
            TernaryFilter::make('ready')
                ->label(__('Ready'))
                ->trueLabel(__('Yes'))
                ->falseLabel(__('Insufficient'))
                ->queries(
                    true: fn (Builder $query): Builder => self::constrainByEmiCashReadiness(
                        $query,
                        $catalog,
                        $month,
                        $year,
                        ready: true,
                    ),
                    false: fn (Builder $query): Builder => self::constrainByEmiCashReadiness(
                        $query,
                        $catalog,
                        $month,
                        $year,
                        ready: false,
                    ),
                ),
        ];
    }

    private static function constrainByEmiCashReadiness(
        Builder $query,
        LoanEmiCollectionCatalogService $catalog,
        int $month,
        int $year,
        bool $ready,
    ): Builder {
        $ids = $catalog->membersWithCollectableEmisQuery($month, $year)
            ->get()
            ->filter(
                fn (Member $member): bool => $catalog->memberHasSufficientCash($member, $month, $year) === $ready,
            )
            ->modelKeys();

        return $query->whereIn('members.id', $ids === [] ? [0] : $ids);
    }

    public static function configureCollectedTable(Table $table): Table
    {
        $catalog = app(LoanEmiCollectionCatalogService::class);
        [$month, $year] = LoanResource::resolveListCycle();
        $currency = Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply(
            $table
                ->query(fn () => $catalog->collectedInstallmentsQuery($month, $year))
                ->heading(__('Collected – :period', [
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
                        ->label(__('Amount collected')),
                    LoanOutstandingColumn::fromLoanResolver(
                        fn (LoanInstallment $record): ?Loan => $record->loan,
                        $currency,
                        sortQuery: fn (Builder $query, string $direction): Builder => $query->orderByLoanOutstanding($direction),
                    ),
                    TextColumn::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->formatStateUsing(fn (string $state, LoanInstallment $record): string => LateSettledArrearsTableStyling::installmentStatusLabel($record))
                        ->color(fn (string $state, LoanInstallment $record): string => LateSettledArrearsTableStyling::installmentStatusColor($record))
                        ->tooltip(fn (LoanInstallment $record): ?string => LateSettledArrearsTableStyling::installmentWasSettledLate($record)
                            ? LateSettledArrearsTableStyling::eligibilityHint()
                            : null),
                    TextColumn::make('late_fee_amount')
                        ->label(__('Late fee'))
                        ->money($currency)
                        ->placeholder(__('—')),
                    TextColumn::make('paid_at')
                        ->label(__('Paid on'))
                        ->dateTime()
                        ->placeholder(__('—'))
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

    public static function configureArrearsTable(Table $table): Table
    {
        $catalog = app(LoanEmiCollectionCatalogService::class);
        [$month, $year] = LoanResource::resolveListCycle();

        return self::configurePendingMembersTable(
            $table,
            __('Arrears – :period', ['period' => $catalog->periodLabel($month, $year)]),
            includeLoanNumber: true,
            includeCollectionFilters: true,
        );
    }
}
