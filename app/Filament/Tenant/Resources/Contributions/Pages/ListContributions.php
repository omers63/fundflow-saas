<?php

namespace App\Filament\Tenant\Resources\Contributions\Pages;

use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Widgets\ContributionInsightsWidget;
use App\Services\ContributionCycleService;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListContributions extends ListRecords
{
    protected static string $resource = ContributionResource::class;

    protected function makeTable(): Table
    {
        $tab = ContributionResource::resolveListTab();

        if (in_array($tab, ['collect', 'collected', 'arrears'], true)) {
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

    public function isTableColumnToggledHidden(string $name): bool
    {
        if (ContributionResource::resolveListTab() === 'arrears') {
            return false;
        }

        return parent::isTableColumnToggledHidden($name);
    }

    public function getTableColumnsSessionKey(): string
    {
        $tab = ContributionResource::resolveListTab();

        if (in_array($tab, ['collect', 'collected', 'arrears'], true)) {
            return 'tables.'.md5(static::class.'|'.$tab).'_columns';
        }

        return parent::getTableColumnsSessionKey();
    }

    public function getHasReorderedTableColumnsSessionKey(): string
    {
        $tab = ContributionResource::resolveListTab();

        if (in_array($tab, ['collect', 'collected', 'arrears'], true)) {
            return 'tables.'.md5(static::class.'|'.$tab).'_has_reordered_columns';
        }

        return parent::getHasReorderedTableColumnsSessionKey();
    }

    public function getFilteredTableQuery(): ?Builder
    {
        if (ContributionResource::resolveListTab() === 'arrears') {
            return null;
        }

        return parent::getFilteredTableQuery();
    }

    public function getSubheading(): string|Htmlable|null
    {
        $cycles = app(ContributionCycleService::class);
        [$month, $year] = $cycles->currentOpenPeriod();
        $openLabel = $cycles->periodLabel($month, $year);

        return match (ContributionResource::resolveListTab()) {
            'collect' => __('Members who still owe for the open period (:period). Apply from cash balance or post manually on the ledger.', [
                'period' => $openLabel,
            ]),
            'collected' => __('Contributions already posted for the open period (:period).', [
                'period' => $openLabel,
            ]),
            'arrears' => __('Unposted contribution periods after the deadline (since each member joined). Post from the member record or ledger.'),
            default => __('Full contribution history, filters, and manual posting. Open period: :period.', [
                'period' => $openLabel,
            ]),
        };
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ContributionInsightsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getTabs(): array
    {
        $pending = ContributionResource::openCyclePendingCount();

        return [
            'collect' => Tab::make(ContributionResource::listTabLabel('collect'))
                ->badge($pending > 0 ? (string) $pending : null)
                ->badgeColor('warning'),
            'collected' => Tab::make(ContributionResource::listTabLabel('collected')),
            'ledger' => Tab::make(ContributionResource::listTabLabel('ledger')),
            'arrears' => Tab::make(ContributionResource::listTabLabel('arrears'))
                ->badge((string) ContributionResource::contributionArrearsPeriodCount())
                ->badgeColor('warning'),
        ];
    }

    protected function getTableQueryStringIdentifier(): ?string
    {
        $tab = ContributionResource::resolveListTab();

        return $tab === 'ledger' ? null : 'contributions-'.$tab;
    }
}
