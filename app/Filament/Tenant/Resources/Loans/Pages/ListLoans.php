<?php

namespace App\Filament\Tenant\Resources\Loans\Pages;

use App\Filament\Support\LoanDelinquencyHeaderActions;
use App\Filament\Support\LoanDelinquencyTables;
use App\Filament\Tenant\Pages\LoanEmiCollectionPage;
use App\Filament\Tenant\Resources\LoanEligibilityOverrides\LoanEligibilityOverrideResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Widgets\LoanInsightsWidget;
use App\Models\Tenant\LoanEligibilityOverrideRequest;
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

        if ($sortColumn && ! $this->getTable()->getSortableVisibleColumn($sortColumn)) {
            $this->tableSort = null;
        }

        return parent::applySortingToTableQuery($query);
    }

    public function getSubheading(): string|Htmlable|null
    {
        return match (LoanResource::resolveListTab()) {
            'overdue_installments' => __('Active loans with installments past due. Run the delinquency check after cycle close to refresh statuses.'),
            'guarantor_exposure' => __('Loans in warning or with liability transferred to the guarantor.'),
            'eligibility_reviews' => __('Review member requests to bypass loan eligibility rules. Approved requests create standing overrides.'),
            default => __('Monitor the full loan portfolio, outstanding balances, and operational pipeline.'),
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('emi_collection')
                ->label(__('EMI collection'))
                ->icon(Heroicon::OutlinedBanknotes)
                ->badge(fn (): ?string => LoanEmiCollectionPage::getNavigationBadge())
                ->badgeColor('warning')
                ->url(fn (): string => LoanEmiCollectionPage::getUrl()),
            ActionGroup::make(LoanDelinquencyHeaderActions::make())
                ->label(__('Delinquency tools'))
                ->icon('heroicon-o-exclamation-triangle')
                ->color('gray')
                ->button()
                ->visible(fn (): bool => LoanResource::resolveListTab() !== 'eligibility_reviews'),
            Action::make('loanOverrides')
                ->label(__('Loan overrides'))
                ->icon(Heroicon::OutlinedShieldCheck)
                ->url(fn (): string => LoanEligibilityOverrideResource::getUrl('index')),
            CreateAction::make()
                ->visible(fn (): bool => LoanResource::resolveListTab() !== 'eligibility_reviews'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        if (LoanResource::resolveListTab() === 'eligibility_reviews') {
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
            'context' => in_array(LoanResource::resolveListTab(), ['overdue_installments', 'guarantor_exposure'], true)
                ? 'delinquency'
                : 'portfolio',
        ];
    }

    public function getTabs(): array
    {
        $tabs = [
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

    protected function getTableQueryStringIdentifier(): ?string
    {
        $tab = LoanResource::resolveListTab();

        return $tab === 'portfolio' ? null : 'loans-'.$tab;
    }
}
