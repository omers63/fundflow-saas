<?php

namespace App\Filament\Tenant\Resources\Accounts\Pages;

use App\Filament\Tenant\Resources\Accounts\AccountResource;
use App\Filament\Tenant\Widgets\MemberAccountsInsightsWidget;
use App\Models\Tenant\Loan;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListAccounts extends ListRecords
{
    protected static string $resource = AccountResource::class;

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

    public function getTitle(): string|Htmlable
    {
        return __('Member Accounts');
    }

    /**
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            MemberAccountsInsightsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getTabs(): array
    {
        return [
            'cash' => Tab::make(__('Cash')),
            'fund' => Tab::make(__('Fund')),
            'loans' => Tab::make(__('Loans')),
            'all' => Tab::make(__('All')),
        ];
    }

    protected function getTableQuery(): Builder
    {
        return match (AccountResource::resolveListMemberAccountsTab()) {
            'fund' => static::getResource()::getEloquentQuery()->where('type', 'fund'),
            'loans' => Loan::query(),
            'all' => static::getResource()::getEloquentQuery(),
            default => static::getResource()::getEloquentQuery()->where('type', 'cash'),
        };
    }
}
