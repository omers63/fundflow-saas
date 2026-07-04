<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\Transactions\Pages;

use App\Filament\Tenant\Resources\Transactions\TransactionResource;
use App\Filament\Tenant\Widgets\TransactionsInsightsWidget;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListTransactions extends ListRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = TransactionResource::class;

    public function getTitle(): string
    {
        return __('Transactions');
    }

    public function getSubheading(): ?string
    {
        return __('Browse posted member and master ledger lines from one place.');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            TransactionsInsightsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getTableQueryForInsights(): ?Builder
    {
        return $this->getFilteredTableQuery();
    }
}
