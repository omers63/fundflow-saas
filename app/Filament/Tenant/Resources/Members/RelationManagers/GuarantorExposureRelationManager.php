<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\Members\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\Members\MemberGuarantorExposureService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class GuarantorExposureRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected string $view = 'filament.tenant.resources.members.relation-managers.guarantor-exposure';

    protected static string $relationship = 'guaranteedLoans';

    protected static ?string $title = 'Guarantor';

    public function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        if (! parent::canViewForRecord($ownerRecord, $pageClass)) {
            return false;
        }

        if (! $ownerRecord instanceof Member) {
            return false;
        }

        return app(MemberGuarantorExposureService::class)->memberHasGuaranteedLoans($ownerRecord);
    }

    /**
     * @return array{
     *     loan_count: int,
     *     total_exposure: float,
     *     max_single_exposure: float,
     *     has_risk: bool,
     *     delinquent_count: int,
     * }
     */
    public function exposureSummary(): array
    {
        $member = $this->getOwnerRecord();
        assert($member instanceof Member);

        return app(MemberGuarantorExposureService::class)->summaryForMember($member);
    }

    public function table(Table $table): Table
    {
        $member = $this->getOwnerRecord();
        assert($member instanceof Member);

        $currency = Setting::get('general', 'currency', 'USD');
        $exposureService = app(MemberGuarantorExposureService::class);

        return TableGrouping::apply(
            $table
                ->modifyQueryUsing(
                    fn ($query) => $exposureService->guaranteedLoansQuery($member)
                )
                ->columnManager(true)
                ->columns([
                    TextColumn::make('id')
                        ->label(__('Loan #'))
                        ->url(fn (Loan $record): string => LoanResource::getUrl('view', ['record' => $record])),
                    TextColumn::make('member.name')
                        ->label(__('Borrower'))
                        ->searchable()
                        ->wrap(),
                    TextColumn::make('amount_approved')
                        ->label(__('Approved'))
                        ->money($currency)
                        ->placeholder(__('—')),
                    TextColumn::make('outstanding')
                        ->label(__('Outstanding'))
                        ->money($currency)
                        ->getStateUsing(fn (Loan $record): float => $record->getOutstandingBalance())
                        ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByOutstanding($direction)),
                    TextColumn::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => __(ucfirst(str_replace('_', ' ', $state))))
                        ->color(fn (string $state): string => Loan::statusColor($state)),
                    TextColumn::make('exposure_risk')
                        ->label(__('Exposure risk'))
                        ->badge()
                        ->state(fn (Loan $record): string => $exposureService->exposureRiskLabel($record))
                        ->color(fn (Loan $record): string => $exposureService->loanHasExposureRisk($record) ? 'danger' : 'success'),
                ])
                ->filters([
                    SelectFilter::make('status')
                        ->options(Loan::statusOptions()),
                ])
                ->recordActions(TableRecordActionGroups::wrap([
                    ViewAction::make()
                        ->url(fn (Loan $record): string => LoanResource::getUrl('view', ['record' => $record])),
                ]))
                ->toolbarActions([
                    BulkActionGroup::make([
                        TableToolbar::refreshBulkAction(),
                    ]),
                ])
                ->defaultSort('applied_at', 'desc')
                ->emptyStateHeading(__('No guaranteed loans'))
                ->emptyStateDescription(__('This member is not a guarantor on any active or historical loans.')),
            TableGrouping::loans(includeMember: false),
        );
    }
}
