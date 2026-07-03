<?php

namespace App\Filament\Tenant\Resources\MasterAccounts\Pages;

use App\Filament\Tenant\Resources\MasterAccounts\MasterAccountResource;
use App\Filament\Tenant\Widgets\MasterAccountsInsightsWidget;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListMasterAccounts extends ListRecords
{
    protected static string $resource = MasterAccountResource::class;

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

    protected function getTableQuery(): Builder
    {
        return static::getResource()::getEloquentQuery()
            ->withLastActivityAt();
    }
}
