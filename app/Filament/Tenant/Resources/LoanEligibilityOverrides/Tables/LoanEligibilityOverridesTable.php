<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\LoanEligibilityOverrides\Tables;

use App\Filament\Support\MemberTableColumns;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\LoanEligibilityOverride;
use App\Services\Loans\LoanEligibilityOverrideService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LoanEligibilityOverridesTable
{
    public static function configure(Table $table): Table
    {
        return TableGrouping::apply($table
            ->columns([
                MemberTableColumns::relationName(),
                TextColumn::make('gate')->searchable(),
                TextColumn::make('reason')->wrap()->limit(80),
                TextColumn::make('approver.name')->placeholder(__('—')),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->schema([
                        Select::make('member_id')
                            ->relationship('member', 'name')
                            ->searchable()
                            ->required(),
                        Select::make('gate')
                            ->options([
                                'min_fund_balance' => __('Minimum fund balance'),
                                'active_loan' => __('Active loan limit'),
                                'delinquency' => __('Delinquency'),
                                'other' => __('Other'),
                            ])
                            ->required(),
                        Textarea::make('reason')->required()->rows(3),
                    ])
                    ->using(function (array $data, LoanEligibilityOverrideService $service): LoanEligibilityOverride {
                        return $service->record(
                            (int) $data['member_id'],
                            (string) $data['gate'],
                            (string) $data['reason'],
                        );
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ]), TableGrouping::loanEligibilityOverrides());
    }
}
