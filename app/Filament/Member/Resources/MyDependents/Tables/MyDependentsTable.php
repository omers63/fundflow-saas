<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyDependents\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\ContributionCycleService;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class MyDependentsTable
{
    public static function configure(Table $table): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');
        [$openMonth, $openYear] = app(ContributionCycleService::class)->currentOpenPeriod();

        return TableGrouping::apply($table
            ->columns([
                TextColumn::make('member_number')
                    ->label('Member #')
                    ->searchable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('monthly_contribution_amount')
                    ->label('Monthly contribution')
                    ->money($currency)
                    ->sortable(),
                TextColumn::make('cash_balance')
                    ->label('Cash')
                    ->state(fn (Member $record): float => $record->getCashBalance())
                    ->money($currency)
                    ->sortable(false),
                TextColumn::make('fund_balance')
                    ->label('Fund')
                    ->state(fn (Member $record): float => $record->getFundBalance())
                    ->money($currency)
                    ->sortable(false),
                TextColumn::make('open_cycle_status')
                    ->label('Open cycle')
                    ->badge()
                    ->state(function (Member $record) use ($openMonth, $openYear): string {
                        $row = Contribution::query()
                            ->where('member_id', $record->id)
                            ->forPeriod($openMonth, $openYear)
                            ->first();

                        if ($row === null) {
                            return __('Not started');
                        }

                        return match ($row->status) {
                            'posted' => __('Posted'),
                            'pending' => __('Pending'),
                            'failed' => __('Failed'),
                            default => ucfirst($row->status),
                        };
                    })
                    ->color(function (Member $record) use ($openMonth, $openYear): string {
                        $row = Contribution::query()
                            ->where('member_id', $record->id)
                            ->forPeriod($openMonth, $openYear)
                            ->first();

                        return match ($row?->status) {
                            'posted' => 'success',
                            'pending' => 'warning',
                            'failed' => 'danger',
                            default => 'gray',
                        };
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Member::statusOptions()[$state] ?? ucfirst($state))
                    ->color(fn (string $state): string => Member::statusBadgeColor($state)),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(Member::statusOptions()),
                DateColumnRangeFilter::make('joined_at', __('Joined')),
            ])
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
            ->defaultSort('name'), TableGrouping::members());
    }
}
