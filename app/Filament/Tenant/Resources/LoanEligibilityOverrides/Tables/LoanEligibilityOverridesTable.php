<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\LoanEligibilityOverrides\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\MemberTableColumns;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\LoanEligibilityOverride;
use App\Services\Loans\LoanEligibilityOverrideService;
use App\Support\LoanEligibilityGate;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LoanEligibilityOverridesTable
{
    public static function createAction(): CreateAction
    {
        return CreateAction::make()
            ->icon('heroicon-o-plus-circle')
            ->schema([
                Select::make('member_id')
                    ->relationship('member', 'name')
                    ->searchable()
                    ->required(),
                Select::make('gate')
                    ->options(LoanEligibilityGate::labels())
                    ->required(),
                Textarea::make('reason')->required()->rows(3),
            ])
            ->using(function (array $data, LoanEligibilityOverrideService $service): LoanEligibilityOverride {
                return $service->record(
                    (int) $data['member_id'],
                    (string) $data['gate'],
                    (string) $data['reason'],
                );
            });
    }

    public static function configure(Table $table): Table
    {
        return TableGrouping::apply($table
            ->columns([
                MemberTableColumns::relationNumber(),
                MemberTableColumns::relationName(),
                TextColumn::make('gate')
                    ->formatStateUsing(fn (string $state): string => LoanEligibilityGate::labels()[$state] ?? $state)
                    ->searchable(),
                TextColumn::make('reason')->wrap()->limit(80),
                TextColumn::make('approver.name')->placeholder(__('—')),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('gate')
                    ->options(LoanEligibilityGate::labels()),
                DateColumnRangeFilter::make('created_at', __('Created')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ]), TableGrouping::loanEligibilityOverrides());
    }
}
