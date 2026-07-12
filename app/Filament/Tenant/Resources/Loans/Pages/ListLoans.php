<?php

namespace App\Filament\Tenant\Resources\Loans\Pages;

use App\Filament\Support\CollectionCalendarHeaderAction;
use App\Filament\Support\LoanDelinquencyTables;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Widgets\LoanInsightsWidget;
use App\Models\Tenant\LoanEligibilityOverrideRequest;
use App\Services\ContributionCycleService;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListLoans extends ListRecords
{
    protected static string $resource = LoanResource::class;

    #[Url(as: 'tab', except: 'collection')]
    public ?string $activeTab = 'collection';

    #[Url(as: 'segment', except: 'collect')]
    public ?string $collectionSegment = 'collect';

    #[Url(as: 'view')]
    public ?string $delinquencyView = null;

    #[Url(as: 'portfolioView')]
    public ?string $portfolioView = null;

    #[Url(as: 'cycle')]
    public ?string $selectedCycle = null;

    public function mount(): void
    {
        $legacyTab = request()->query('tab');

        if (is_string($legacyTab) && in_array($legacyTab, LoanResource::legacyTabKeys(), true)) {
            $this->redirect(
                LoanResource::listTabUrl($legacyTab),
                navigate: true,
            );

            return;
        }

        parent::mount();

        if (! filled($this->selectedCycle)) {
            $cycles = app(ContributionCycleService::class);
            [$month, $year] = $cycles->currentOpenPeriod();
            $this->selectedCycle = $cycles->contributionCycleKey($month, $year);
        }
    }

    public function updatedSelectedCycle(): void
    {
        if (LoanResource::resolvePrimaryTab() === 'collection') {
            $this->reconfigureTableForActiveTab();
            LoanResource::dispatchInsightsRefresh($this);
        }
    }

    public function updatedCollectionSegment(): void
    {
        $this->reconfigureTableForActiveTab();
        LoanResource::dispatchInsightsRefresh($this);
    }

    public function updatedDelinquencyView(): void
    {
        $this->reconfigureTableForActiveTab();
        LoanResource::dispatchInsightsRefresh($this);
    }

    public function updatedPortfolioView(): void
    {
        $this->reconfigureTableForActiveTab();
        LoanResource::dispatchInsightsRefresh($this);
    }

    protected function makeTable(): Table
    {
        return parent::makeTable();
    }

    public function updatedActiveTab(): void
    {
        if ($this->activeTab !== 'delinquency') {
            $this->delinquencyView = null;
        }

        if ($this->activeTab !== 'portfolio') {
            $this->portfolioView = null;
        }

        if ($this->activeTab !== 'collection') {
            $this->collectionSegment = 'collect';
        }

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
        return match (LoanResource::resolvePrimaryTab()) {
            'collection' => match (LoanResource::resolveCollectionSegment()) {
                'collected' => __('Installments already collected from member cash for :period.', [
                    'period' => LoanResource::resolveListCycleLabel(),
                ]),
                default => __('Members with pending EMIs through :period. Apply from cash balance (open period and arrears only).', [
                    'period' => LoanResource::resolveListCycleLabel(),
                ]),
            },
            'delinquency' => match (LoanResource::resolveDelinquencyView()) {
                'guarantor' => __('Loans in warning or with liability transferred to the guarantor.'),
                default => __('Active loans with installments past due. Run the delinquency check after cycle close to refresh statuses.'),
            },
            'portfolio' => LoanResource::resolvePortfolioView() === 'eligibility'
            ? __('Review member requests to bypass loan eligibility rules. Approved requests create standing overrides.')
            : __('Track the full lifecycle of every loan — from application through disbursement to settlement. Monitor outstanding balances and operational pipeline.'),
            default => null,
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            CollectionCalendarHeaderAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        if (
            LoanResource::resolvePortfolioView() === 'eligibility'
            && ! LoanEligibilityOverrideRequest::isTableReady()
        ) {
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
            'context' => LoanResource::resolveInsightsContext(),
        ];
    }

    public function getTabs(): array
    {
        $emiPending = LoanResource::pendingEmiCollectionMemberCount();
        $overdueCount = LoanResource::overdueInstallmentsCount();
        $guarantorCount = LoanResource::guarantorExposureCount();

        $tabs = [
            'collection' => Tab::make(LoanResource::listTabLabel('collection'))
                ->badge($emiPending > 0 ? (string) $emiPending : null)
                ->badgeColor('warning'),
            'portfolio' => Tab::make(LoanResource::listTabLabel('portfolio'))
                ->badge(fn (): ?string => $this->portfolioEligibilityBadge())
                ->badgeColor('warning'),
            'delinquency' => Tab::make(LoanResource::listTabLabel('delinquency'))
                ->badge($overdueCount + $guarantorCount > 0 ? (string) ($overdueCount + $guarantorCount) : null)
                ->badgeColor('danger'),
        ];

        return $tabs;
    }

    protected function portfolioEligibilityBadge(): ?string
    {
        if (! LoanEligibilityOverrideRequest::isTableReady()) {
            return null;
        }

        $pending = LoanResource::pendingEligibilityReviewsCount();

        return $pending > 0 ? (string) $pending : null;
    }

    public function content(Schema $schema): Schema
    {
        $components = [$this->getTabsContentComponent()];

        if (LoanResource::resolvePrimaryTab() === 'collection') {
            $components[] = View::make('filament.tenant.resources.loans.partials.cycle-header');
        }

        $components[] = View::make('filament.tenant.resources.loans.partials.workspace-subnav-wrapper');
        $components[] = RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE);
        $components[] = EmbeddedTable::make();
        $components[] = RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER);

        return $schema->components($components);
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'fi-page-loans',
        ];
    }

    protected function getTableQuery(): Builder
    {
        return match (LoanResource::tableLayoutKey()) {
            'delinquency|overdue' => LoanDelinquencyTables::overdueInstallmentsQuery(),
            'delinquency|guarantor' => LoanDelinquencyTables::guarantorExposureQuery(),
            'portfolio|eligibility' => LoanEligibilityOverrideRequest::query()->with(['member', 'reviewer']),
            default => parent::getTableQuery(),
        };
    }

    public function getTableColumnsSessionKey(): string
    {
        $layout = LoanResource::tableLayoutKey();

        if ($layout !== 'portfolio') {
            return 'tables.'.md5(static::class.'|'.$layout).'_columns';
        }

        return parent::getTableColumnsSessionKey();
    }

    public function getHasReorderedTableColumnsSessionKey(): string
    {
        $layout = LoanResource::tableLayoutKey();

        if ($layout !== 'portfolio') {
            return 'tables.'.md5(static::class.'|'.$layout).'_has_reordered_columns';
        }

        return parent::getHasReorderedTableColumnsSessionKey();
    }

    protected function getTableQueryStringIdentifier(): ?string
    {
        $layout = LoanResource::tableLayoutKey();

        return $layout === 'collection|collect' ? null : 'loans-'.str_replace('|', '-', $layout);
    }
}
