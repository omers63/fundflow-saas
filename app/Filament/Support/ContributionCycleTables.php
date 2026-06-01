<?php

declare(strict_types=1);

namespace App\Filament\Support;

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

final class ContributionCycleTables
{
    public static function configurePendingMembersTable(Table $table): Table
    {
        $cycles = app(ContributionCycleService::class);
        [$month, $year] = $cycles->currentOpenPeriod();
        $currency = Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply($table
            ->query(fn (): Builder => $cycles->pendingMembersQueryForPeriod($month, $year))
            ->headerActions([
                ContributionListTableHeaderActions::cycleActionGroup(),
            ])
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
                    ->alignEnd(),
                TextColumn::make('coverage')
                    ->label(__('Ready'))
                    ->state(function (Member $record) use ($cycles, $month, $year): string {
                        $required = $cycles->requiredCashForMemberPeriod($record, $month, $year);

                        return $record->getCashBalance() >= $required
                            ? __('Yes')
                            : __('Insufficient');
                    })
                    ->badge()
                    ->color(fn (string $state): string => $state === __('Yes') ? 'success' : 'warning'),
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
                    ->action(function (Member $record) use ($month, $year, $cycles): void {
                        $outcome = $cycles->applyContributionForMemberForPeriod($record, $month, $year);

                        Notification::make()
                            ->title(match ($outcome) {
                                'applied' => __('Contribution applied'),
                                'already_contributed' => __('Already recorded'),
                                'exempt' => __('Member exempt'),
                                default => __('Could not apply'),
                            })
                            ->body(match ($outcome) {
                                'applied' => __('Posted for :name.', ['name' => $record->name]),
                                'insufficient' => __('Insufficient cash balance.'),
                                'exempt' => __('Active loan with pending installments.'),
                                default => $outcome,
                            })
                            ->color($outcome === 'applied' ? 'success' : 'warning')
                            ->send();
                    }),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('applySelected')
                        ->label(__('Apply now'))
                        ->icon('heroicon-o-check')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (Collection $records) use ($month, $year, $cycles): void {
                            $applied = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                $outcome = $cycles->applyContributionForMemberForPeriod($record, $month, $year);

                                if ($outcome === 'applied') {
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
                        }),
                    TableToolbar::refreshBulkAction(),
                ]),
            ]), TableGrouping::members());
    }

    public static function configureCollectedTable(Table $table): Table
    {
        $cycles = app(ContributionCycleService::class);
        [$month, $year] = $cycles->currentOpenPeriod();
        $currency = Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply($table
            ->query(fn (): Builder => $cycles->postedContributionsQueryForPeriod($month, $year))
            ->headerActions([
                ContributionListTableHeaderActions::cycleActionGroup(),
            ])
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
