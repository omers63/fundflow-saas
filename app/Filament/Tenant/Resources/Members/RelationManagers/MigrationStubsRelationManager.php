<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\Members\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Support\MigrationStubFilamentActions;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\Member;
use App\Models\Tenant\MigrationCycleStub;
use App\Services\MigrationCycleService;
use App\Services\MigrationSettlementService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class MigrationStubsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'migrationStubs';

    protected static ?string $title = 'Migration cycles';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->migration_status !== null
            || $ownerRecord->migrationStubs()->exists();
    }

    public function table(Table $table): Table
    {
        return TableGrouping::apply($table
            ->columns([
                TextColumn::make('cycle_date')->date()->sortable(),
                TextColumn::make('amount_due')->money(),
                TextColumn::make('status')->badge(),
                TextColumn::make('classification')->badge()->placeholder(__('—')),
                TextColumn::make('resolution_method')->placeholder(__('—')),
            ])
            ->recordActions(TableRecordActionGroups::wrap([
                MigrationStubFilamentActions::classifyRecordAction(),
                Action::make('deleteStub')
                    ->label(__('Delete'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (MigrationCycleStub $record, MigrationCycleService $migration): void {
                        /** @var Member $member */
                        $member = $this->getOwnerRecord();

                        $deleted = $migration->deleteStubsForMember($member, Collection::make([$record]));

                        if ($deleted === 0) {
                            Notification::make()
                                ->title(__('Nothing deleted'))
                                ->warning()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('Cycle deleted'))
                            ->success()
                            ->send();
                    }),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    MigrationStubFilamentActions::classifySelectedBulkAction(),
                    BulkAction::make('deleteSelected')
                        ->label(__('Delete selected'))
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function (Collection $records, MigrationCycleService $migration): void {
                            /** @var Member $member */
                            $member = $this->getOwnerRecord();

                            $deleted = $migration->deleteStubsForMember($member, $records);

                            Notification::make()
                                ->title(__(':count cycle(s) deleted', ['count' => $deleted]))
                                ->success()
                                ->send();
                        }),
                    TableToolbar::refreshBulkAction(),
                ]),
            ]), TableGrouping::migrationCycleStubs())
            ->headerActions([
                Action::make('resetMigration')
                    ->label(__('Delete all cycles & start over'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('Reset migration for this member?'))
                    ->modalDescription(__('Deletes every migration cycle stub and clears migration status so you can enroll this member again from scratch.'))
                    ->action(function (MigrationCycleService $migration): void {
                        /** @var Member $member */
                        $member = $this->getOwnerRecord();

                        $count = $migration->resetMigrationForMember($member);

                        Notification::make()
                            ->title(__(':count cycle(s) removed', ['count' => $count]))
                            ->body(__('Migration enrollment cleared. Use Begin migration when ready.'))
                            ->success()
                            ->send();
                    }),
                Action::make('lump_sum')
                    ->label(__('Lump-sum settlement'))
                    ->action(function (MigrationSettlementService $settlement): void {
                        try {
                            $total = $settlement->applyLumpSumSettlement($this->getOwnerRecord());
                            Notification::make()->title(__('Settled :amount', ['amount' => number_format($total, 2)]))->success()->send();
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('instalment_plan')
                    ->label(__('Instalment plan'))
                    ->action(function (MigrationSettlementService $settlement): void {
                        try {
                            $n = $settlement->buildInstalmentPlan($this->getOwnerRecord());
                            Notification::make()->title(__(':count instalment(s) scheduled', ['count' => $n]))->success()->send();
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('ob_offset')
                    ->label(__('Opening balance offset'))
                    ->action(function (MigrationSettlementService $settlement): void {
                        try {
                            $total = $settlement->applyOpeningBalanceOffset($this->getOwnerRecord());
                            Notification::make()->title(__('Offset :amount', ['amount' => number_format($total, 2)]))->success()->send();
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }
}
