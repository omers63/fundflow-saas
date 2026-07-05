<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\ContributionCycleService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

final class ContributionCycleTables
{
    public static function configurePendingMembersTable(Table $table): Table
    {
        $cycles = app(ContributionCycleService::class);
        [$month, $year] = ContributionResource::resolveListCycle();
        $currency = Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply($table
            ->query(fn (): Builder => $cycles->pendingMembersQueryForPeriod($month, $year))
            ->headerActions(ContributionListTableHeaderActions::collect())
            ->heading(__('To collect – :period', ['period' => $cycles->periodLabel($month, $year)]))
            ->columns([
                MemberTableColumns::number(label: __('Member #'))
                    ->searchable()
                    ->sortable(),
                MemberTableColumns::name(label: __('Member'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('monthly_contribution_amount')
                    ->label(__('Required'))
                    ->sortable()
                    ->money($currency),
                TextColumn::make('available_cash')
                    ->label(__('Cash balance'))
                    ->state(fn (Member $record): float => $record->getCashBalance())
                    ->money($currency)
                    ->color(fn (Member $record): string => $record->getCashBalance() < 0 ? 'danger' : 'gray')
                    ->alignEnd()
                    ->searchable(false)
                    ->sortable(query: function (Builder $query, string $direction): void {
                        $query->orderBy(
                            Account::query()
                                ->select('balance')
                                ->whereColumn('accounts.member_id', 'members.id')
                                ->where('type', 'cash')
                                ->where('is_master', false)
                                ->limit(1),
                            $direction,
                        );
                    }),
                TextColumn::make('coverage')
                    ->label(__('Ready'))
                    ->state(function (Member $record) use ($cycles, $month, $year): string {
                        $required = $cycles->requiredCashForMemberPeriod($record, $month, $year);

                        return $record->getCashBalance() >= $required
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
            ->recordActions(TableRecordActionGroups::wrap([
                Action::make('apply_single')
                    ->label(__('Apply now'))
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Member $record, Action $action, Component $livewire) use ($month, $year, $cycles): void {
                        $outcome = $cycles->applyContributionForMemberForPeriod($record, $month, $year);

                        ContributionTableActions::notifyApplyOutcome($outcome, $record->name, $action);

                        if (in_array($outcome, ['applied', 'partial'], true)) {
                            ContributionTableActions::refreshContributionViews($livewire);
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
                        ->action(function (Collection $records, Component $livewire) use ($month, $year, $cycles): void {
                            $applied = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                $outcome = $cycles->applyContributionForMemberForPeriod($record, $month, $year);

                                if (in_array($outcome, ['applied', 'partial'], true)) {
                                    $applied++;
                                } else {
                                    $skipped++;
                                }
                            }

                            Notification::make()
                                ->title(__('Bulk apply complete'))
                                ->body(__(':applied applied, :skipped skipped or could not apply.', [
                                    'applied' => $applied,
                                    'skipped' => $skipped,
                                ]))
                                ->color($applied > 0 ? 'success' : 'warning')
                                ->send();

                            if ($applied > 0) {
                                ContributionTableActions::refreshContributionViews($livewire);
                            }
                        }),
                    TableToolbar::refreshBulkAction(),
                ]),
            ]), TableGrouping::members());
    }

    public static function configureCollectedTable(Table $table): Table
    {
        $cycles = app(ContributionCycleService::class);
        [$month, $year] = ContributionResource::resolveListCycle();
        $currency = Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply($table
            ->query(fn (): Builder => $cycles->postedContributionsQueryForPeriod($month, $year))
            ->heading(__('Collected – :period', ['period' => $cycles->periodLabel($month, $year)]))
            ->columns([
                MemberTableColumns::relationNumber(),
                MemberTableColumns::relationName(),
                TextColumn::make('amount')->money($currency),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state, Contribution $record): string => LateSettledArrearsTableStyling::contributionStatusLabel($record))
                    ->color(fn (string $state, Contribution $record): string => LateSettledArrearsTableStyling::contributionStatusColor($record))
                    ->tooltip(fn (Contribution $record): ?string => LateSettledArrearsTableStyling::contributionWasSettledLate($record)
                        ? LateSettledArrearsTableStyling::eligibilityHint()
                        : null),
                TextColumn::make('posted_at')->dateTime()->placeholder(__('—')),
            ])
            ->recordClasses(fn (Contribution $record): ?string => LateSettledArrearsTableStyling::contributionRecordClasses($record))
            ->recordActions(TableRecordActionGroups::wrap([]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ]), TableGrouping::contributions(includeMember: false));
    }
}
