<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyDependents\Tables;

use App\Filament\Member\Resources\MyDependents\Support\DependentOpenCycleStatus;
use App\Filament\Member\Resources\MyDependents\Support\DependentsTableHeaderActions;
use App\Filament\Member\Resources\MyDependents\Support\MyDependentTableActions;
use App\Filament\Support\MoneyDisplay;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\DependentAllocationChange;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\ContributionCycleService;
use App\Support\Tenant\CurrentMember;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class MyDependentsTable
{
    public static function configure(Table $table): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');
        [$openMonth, $openYear] = app(ContributionCycleService::class)->currentOpenPeriod();

        $cycleStatus = fn (Member $record): array => DependentOpenCycleStatus::resolve($record, $openMonth, $openYear);

        return $table
            ->headerActions(DependentsTableHeaderActions::actions())
            ->emptyStateHeading(__('No dependents'))
            ->emptyStateDescription(function (): string {
                $member = CurrentMember::get();

                if ($member === null) {
                    return __('Your dependents will appear here once they are linked to your household.');
                }

                if ($member->isSponsoredDependent()) {
                    return __('You are linked to a household parent. Use Add a dependent above to become a parent and request a new dependent.');
                }

                return __('Your dependents will appear here once they are linked to your household.');
            })
            ->columns([
                TextColumn::make('member_number')
                    ->label(__('Member #'))
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('Dependent'))
                    ->searchable(['name', 'member_number'])
                    ->sortable()
                    ->wrap(),
                TextColumn::make('household_funding')
                    ->label(__('Funding'))
                    ->badge()
                    ->sortable(false)
                    ->searchable(false)
                    ->state(fn (Member $record): string => $record->isFundedByParent()
                        ? __('Funded')
                        : __('Self-funded'))
                    ->color(fn (Member $record): string => $record->isFundedByParent() ? 'success' : 'gray')
                    ->description(fn (Member $record): ?string => $record->isFundedByParent()
                        ? MoneyDisplay::format((float) $record->monthly_contribution_amount, $currency, precision: 0)
                        : null),
                TextColumn::make('cash_balance')
                    ->label(__('Cash'))
                    ->state(fn (Member $record): float => $record->getCashBalance())
                    ->money($currency)
                    ->color(fn (Member $record): string => $record->getCashBalance() < 0 ? 'danger' : 'gray')
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('open_cycle_status')
                    ->label(__('This cycle'))
                    ->badge()
                    ->sortable(false)
                    ->searchable(false)
                    ->state(fn (Member $record): string => $cycleStatus($record)['label'])
                    ->color(fn (Member $record): string => $cycleStatus($record)['color'])
                    ->description(fn (Member $record): ?string => $cycleStatus($record)['description']),
                TextColumn::make('fund_balance')
                    ->label(__('Fund'))
                    ->state(fn (Member $record): float => $record->getFundBalance())
                    ->money($currency)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Member::statusOptions()[$state] ?? ucfirst($state))
                    ->color(fn (string $state): string => Member::statusBadgeColor($state))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_allocation_change')
                    ->label(__('Last changed'))
                    ->sortable(false)
                    ->searchable(false)
                    ->state(function (Member $record) use ($currency): ?string {
                        if (! Schema::hasTable('dependent_allocation_changes')) {
                            return null;
                        }

                        $last = DependentAllocationChange::query()
                            ->where('dependent_member_id', $record->id)
                            ->latest()
                            ->first();

                        if ($last === null) {
                            return null;
                        }

                        $dir = $last->isIncrease() ? '↑' : '↓';

                        return "{$dir} {$last->deltaLabel($currency)} · {$last->created_at->diffForHumans()}";
                    })
                    ->placeholder(__('Never changed'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(Member::statusOptions()),
            ])
            ->recordActions(MyDependentTableActions::recordActions())
            ->recordUrl(function (Model $record): ?string {
                if (! $record instanceof Member) {
                    return null;
                }

                if (in_array($record->status, Member::PORTAL_BLOCKED_STATUSES, true)) {
                    return null;
                }

                return route('tenant.member.dependents.impersonate', ['dependent' => $record]);
            })
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ])
            ->defaultSort('name');
    }
}
