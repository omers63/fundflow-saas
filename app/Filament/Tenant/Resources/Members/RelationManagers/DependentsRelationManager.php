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
use App\Filament\Tenant\Resources\Members\Concerns\SuppressesMemberWorkspaceTabBadges;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class DependentsRelationManager extends RelationManager
{
    use InteractsWithMemberContributionHeaderActions;
    use SuppressesMemberWorkspaceTabBadges;
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'dependents';

    protected static ?string $title = 'Household';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        if (! parent::canViewForRecord($ownerRecord, $pageClass)) {
            return false;
        }

        if (! $ownerRecord instanceof Member) {
            return false;
        }

        return $ownerRecord->dependents()->exists();
    }

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
                    ->formatStateUsing(fn (string $state, Member $record): string => $record->adminStatusLabel())
                    ->color(fn (Member $record): string => $record->adminStatusBadgeColor()),
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
            ->recordUrl(fn (Member $record): string => MemberResource::getUrl('view', ['record' => $record]))
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
