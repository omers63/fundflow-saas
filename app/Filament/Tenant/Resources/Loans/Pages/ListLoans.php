<?php

namespace App\Filament\Tenant\Resources\Loans\Pages;

use App\Filament\Support\LoanDelinquencyTables;
use App\Filament\Support\LoanEmiCollectionHeaderActions;
use App\Filament\Tenant\Pages\LoanEmiCollectionCalendarPage;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Widgets\LoanInsightsWidget;
use App\Models\Tenant\LoanEligibilityOverrideRequest;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListLoans extends ListRecords
{
    protected static string $resource = LoanResource::class;

    #[Url(as: 'tab')]
    public ?string $activeTab = 'portfolio';

    protected function makeTable(): Table
    {
        $tab = LoanResource::resolveListTab();

        if (in_array($tab, ['emi_collect', 'emi_collected', 'overdue_installments', 'guarantor_exposure', 'eligibility_reviews'], true)) {
            $table = $this->makeBaseTable();

            static::getResource()::configureTable($table);

            return $table;
        }

        return parent::makeTable();
    }

    public function updatedActiveTab(): void
    {
        $this->tableSort = null;
        $this->reconfigureTableForActiveTab();

        parent::updatedActiveTab();
    }

    protected function reconfigureTableForActiveTab(): void
    {
        $this->table = $this->table($this->makeTable());

        $this->cacheSchema('tableFiltersForm', $this->getTableFiltersForm(...));

        $this->initTableColumnManager();

        $this->tableFilters = [];
        $this->getTableFiltersForm()->fill([]);
    }

    protected function applySortingToTableQuery(Builder $query): Builder
    {
        $sortColumn = $this->getTableSortColumn();

        if ($sortColumn && ! $this->getTable()->getSortableVisibleColumn($sortColumn)) {
            $this->tableSort = null;
        }

        return parent::applySortingToTableQuery($query);
    }

    public function getSubheading(): string|Htmlable|null
    {
        return match (LoanResource::resolveListTab()) {
            'emi_collect' => __('Members with pending EMIs through the open period. Apply from cash balance (open period and arrears only).'),
            'emi_collected' => __('Installments already collected from member cash for the open period.'),
            'overdue_installments' => __('Active loans with installments past due. Run the delinquency check after cycle close to refresh statuses.'),
            'guarantor_exposure' => __('Loans in warning or with liability transferred to the guarantor.'),
            'eligibility_reviews' => __('Review member requests to bypass loan eligibility rules. Approved requests create standing overrides.'),
            default => __('Track the full lifecycle of every loan — from application through disbursement to settlement.
                Monitor the full loan portfolio, outstanding balances, and operational pipeline.'),
        };
    }

    protected function getHeaderActions(): array
    {
        $actions = [];

        if (LoanResource::resolveListTab() === 'emi_collect') {
            $actions[] = LoanEmiCollectionHeaderActions::cycleCollectionGroup();
        }

        $actions[] = Action::make('collection_calendar')
            ->label(__('Collection calendar'))
            ->icon('heroicon-o-calendar-days')
            ->color('gray')
            ->url(LoanEmiCollectionCalendarPage::getUrl());

        return $actions;
    }

    protected function getHeaderWidgets(): array
    {
        if (LoanResource::resolveListTab() === 'eligibility_reviews' && ! LoanEligibilityOverrideRequest::isTableReady()) {
            return [];
        }

        return [
            LoanInsightsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return [
            ...parent::getWidgetData(),
            'context' => match (LoanResource::resolveListTab()) {
                'emi_collect' => 'emi_collect',
                'emi_collected' => 'emi_collected',
                'overdue_installments', 'guarantor_exposure' => 'delinquency',
                'eligibility_reviews' => 'eligibility_reviews',
                default => 'portfolio',
            },
        ];
    }

    public function getTabs(): array
    {
        $emiPending = LoanResource::pendingEmiCollectionMemberCount();

        $tabs = [
            'emi_collect' => Tab::make(LoanResource::listTabLabel('emi_collect'))
                ->badge($emiPending > 0 ? (string) $emiPending : null)
                ->badgeColor('warning'),
            'emi_collected' => Tab::make(LoanResource::listTabLabel('emi_collected')),
            'portfolio' => Tab::make(LoanResource::listTabLabel('portfolio')),
            'overdue_installments' => Tab::make(LoanResource::listTabLabel('overdue_installments'))
                ->badge((string) LoanResource::overdueInstallmentsCount())
                ->badgeColor('danger'),
            'guarantor_exposure' => Tab::make(LoanResource::listTabLabel('guarantor_exposure'))
                ->badge((string) LoanResource::guarantorExposureCount())
                ->badgeColor('warning'),
        ];

        if (LoanEligibilityOverrideRequest::isTableReady()) {
            $pending = LoanResource::pendingEligibilityReviewsCount();

            $tabs['eligibility_reviews'] = Tab::make(LoanResource::listTabLabel('eligibility_reviews'))
                ->badge($pending > 0 ? (string) $pending : null)
                ->badgeColor('warning');
        }

        return $tabs;
    }

    protected function getTableQuery(): Builder
    {
        return match (LoanResource::resolveListTab()) {
            'overdue_installments' => LoanDelinquencyTables::overdueInstallmentsQuery(),
            'guarantor_exposure' => LoanDelinquencyTables::guarantorExposureQuery(),
            'eligibility_reviews' => LoanEligibilityOverrideRequest::query()->with(['member', 'reviewer']),
            default => parent::getTableQuery(),
        };
    }

    public function getTableColumnsSessionKey(): string
    {
        $tab = LoanResource::resolveListTab();

        if (in_array($tab, ['emi_collect', 'emi_collected', 'overdue_installments', 'guarantor_exposure', 'eligibility_reviews'], true)) {
            return 'tables.'.md5(static::class.'|'.$tab).'_columns';
        }

        return parent::getTableColumnsSessionKey();
    }

    public function getHasReorderedTableColumnsSessionKey(): string
    {
        $tab = LoanResource::resolveListTab();

        if (in_array($tab, ['emi_collect', 'emi_collected', 'overdue_installments', 'guarantor_exposure', 'eligibility_reviews'], true)) {
            return 'tables.'.md5(static::class.'|'.$tab).'_has_reordered_columns';
        }

        return parent::getHasReorderedTableColumnsSessionKey();
    }

    protected function getTableQueryStringIdentifier(): ?string
    {
        $tab = LoanResource::resolveListTab();

        return $tab === 'portfolio' ? null : 'loans-'.$tab;
    }
}
