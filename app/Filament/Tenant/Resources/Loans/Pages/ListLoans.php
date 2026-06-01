<?php

namespace App\Filament\Tenant\Resources\Loans\Pages;

use App\Filament\Support\LoanDelinquencyHeaderActions;
use App\Filament\Support\LoanDelinquencyTables;
use App\Filament\Tenant\Resources\LoanEligibilityOverrides\LoanEligibilityOverrideResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Widgets\LoanInsightsWidget;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListLoans extends ListRecords
{
    protected static string $resource = LoanResource::class;

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

        if ($sortColumn && !$this->getTable()->getSortableVisibleColumn($sortColumn)) {
            $this->tableSort = null;
        }

        return parent::applySortingToTableQuery($query);
    }

    public function getSubheading(): string|Htmlable|null
    {
        return match (LoanResource::resolveListTab()) {
            'overdue_installments' => __('Active loans with installments past due. Run the delinquency check after cycle close to refresh statuses.'),
            'guarantor_exposure' => __('Loans in warning or with liability transferred to the guarantor.'),
            default => __('Monitor the full loan portfolio, outstanding balances, and operational pipeline.'),
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make(LoanDelinquencyHeaderActions::make($this))
                ->label(__('Delinquency tools'))
                ->icon('heroicon-o-exclamation-triangle')
                ->color('gray')
                ->button(),
            Action::make('loanOverrides')
                ->label(__('Loan overrides'))
                ->icon(Heroicon::OutlinedShieldCheck)
                ->url(fn(): string => LoanEligibilityOverrideResource::getUrl('index')),
            CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
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
            'context' => in_array(LoanResource::resolveListTab(), ['overdue_installments', 'guarantor_exposure'], true)
                ? 'delinquency'
                : 'portfolio',
        ];
    }

    public function getTabs(): array
    {
        return [
            'portfolio' => Tab::make(LoanResource::listTabLabel('portfolio')),
            'overdue_installments' => Tab::make(LoanResource::listTabLabel('overdue_installments'))
                ->badge((string) LoanResource::overdueInstallmentsCount())
                ->badgeColor('danger'),
            'guarantor_exposure' => Tab::make(LoanResource::listTabLabel('guarantor_exposure'))
                ->badge((string) LoanResource::guarantorExposureCount())
                ->badgeColor('warning'),
        ];
    }

    protected function getTableQuery(): Builder
    {
        return match (LoanResource::resolveListTab()) {
            'overdue_installments' => LoanDelinquencyTables::overdueInstallmentsQuery(),
            'guarantor_exposure' => LoanDelinquencyTables::guarantorExposureQuery(),
            default => parent::getTableQuery(),
        };
    }

    protected function getTableQueryStringIdentifier(): ?string
    {
        $tab = LoanResource::resolveListTab();

        return $tab === 'portfolio' ? null : 'loans-' . $tab;
    }
}
