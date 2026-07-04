<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Filament\Tenant\Resources\Transactions\Pages\ListTransactions;
use App\Services\TransactionsInsightsService;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;

class TransactionsInsightsWidget extends Widget
{
    use InteractsWithPageTable;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected string $view = 'filament.tenant.widgets.transactions-insights';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $query = $this->getInsightsQuery();

        if ($query === null) {
            return [];
        }

        return app(TransactionsInsightsService::class)->snapshot(
            $query,
            $this->tableFilters ?? [],
            $this->tableSearch,
        );
    }

    protected function getTablePage(): string
    {
        return ListTransactions::class;
    }

    protected function getInsightsQuery(): ?Builder
    {
        $page = $this->getTablePageInstance();

        if (method_exists($page, 'getTableQueryForInsights')) {
            return $page->getTableQueryForInsights();
        }

        return $page->getFilteredTableQuery();
    }
}
