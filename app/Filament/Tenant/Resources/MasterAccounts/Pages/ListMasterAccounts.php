<?php

namespace App\Filament\Tenant\Resources\MasterAccounts\Pages;

use App\Filament\Tenant\Resources\MasterAccounts\MasterAccountResource;
use App\Filament\Tenant\Widgets\MasterAccountsInsightsWidget;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListMasterAccounts extends ListRecords
{
    protected static string $resource = MasterAccountResource::class;

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
        return __('Master Accounts');
    }

    /**
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            MasterAccountsInsightsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getTabs(): array
    {
        return collect(MasterAccountResource::tabKeys())
            ->mapWithKeys(fn (string $tab): array => [
                $tab => Tab::make(MasterAccountResource::tabLabel($tab)),
            ])
            ->all();
    }

    protected function getTableQuery(): Builder
    {
        $query = static::getResource()::getEloquentQuery();

        $type = MasterAccountResource::resolveListMasterAccountsTab();

        if ($type === 'all') {
            return $query;
        }

        return $query->where('type', $type);
    }
}
