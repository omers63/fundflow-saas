<?php

namespace App\Filament\Tenant\Resources\Members\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\HouseholdDependentFilamentActions;
use App\Filament\Support\MemberTableColumns;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Resources\Members\Concerns\InteractsWithMemberContributionHeaderActions;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DependentsRelationManager extends RelationManager
{
    use InteractsWithMemberContributionHeaderActions;
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'dependents';

    protected static ?string $title = 'Household';

    public function table(Table $table): Table
    {
        return TableGrouping::apply($table
            ->recordTitleAttribute('name')
            ->columns([
                MemberTableColumns::number(label: __('Member #'))
                    ->searchable(),
                MemberTableColumns::name()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('monthly_contribution_amount')
                    ->label('Monthly contribution')
                    ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Member::statusOptions()[$state] ?? ucfirst($state))
                    ->color(fn (string $state): string => Member::statusBadgeColor($state)),
                TextColumn::make('joined_at')
                    ->label('Joined')
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(Member::statusOptions()),
                DateColumnRangeFilter::make('joined_at', 'Joined'),
            ])
            ->headerActions([
                ...HouseholdDependentFilamentActions::headerActions(fn (): Member => $this->getOwnerRecord()),
                $this->buildMemberAllocateDependentsAction(),
            ])
            ->recordUrl(fn (Member $record): string => MemberResource::getUrl('edit', ['record' => $record]))
            ->recordActions(TableRecordActionGroups::wrap(
                HouseholdDependentFilamentActions::forRow(fn (): Member => $this->getOwnerRecord()),
            ))
            ->toolbarActions([
                BulkActionGroup::make([
                    ...HouseholdDependentFilamentActions::forBulk(fn (): Member => $this->getOwnerRecord()),
                    TableToolbar::refreshBulkAction(),
                ]),
            ])
            ->defaultSort('name'), TableGrouping::members());
    }
}
