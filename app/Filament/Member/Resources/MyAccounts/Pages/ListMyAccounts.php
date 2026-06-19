<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyAccounts\Pages;

use App\Filament\Member\Resources\MyAccounts\MyAccountResource;
use App\Filament\Member\Resources\MyFundPostings\MyFundPostingResource;
use App\Models\Tenant\Loan;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListMyAccounts extends ListRecords
{
    protected static string $resource = MyAccountResource::class;

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
        return __('My Accounts');
    }

    public function getSubheading(): ?string
    {
        return __('Cash, fund balances, ledger activity, and loans in one place.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newDeposit')
                ->label(__('New deposit'))
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->url(MyFundPostingResource::getUrl('create')),
        ];
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
        $memberId = auth('tenant')->user()?->member?->id;

        return match (MyAccountResource::resolveListMyAccountsTab()) {
            'fund' => static::getResource()::getEloquentQuery()->where('type', 'fund'),
            'loans' => Loan::query()->where('member_id', $memberId),
            'all' => static::getResource()::getEloquentQuery(),
            default => static::getResource()::getEloquentQuery()->where('type', 'cash'),
        };
    }
}
