<?php

namespace App\Filament\Tenant\Resources\Contributions\Pages;

use App\Filament\Support\CollectionCalendarHeaderAction;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Widgets\ContributionInsightsWidget;
use App\Services\ContributionCycleService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

class ListContributions extends ListRecords
{
    protected static string $resource = ContributionResource::class;

    #[Url(as: 'tab', except: 'cycle')]
    public ?string $activeTab = 'cycle';

    #[Url(as: 'cycle')]
    public ?string $selectedCycle = null;

    #[Url(as: 'segment', except: 'collect')]
    public ?string $cycleSegment = 'collect';

    #[Url(as: 'view')]
    public ?string $ledgerView = null;

    public function mount(): void
    {
        $legacyTab = request()->query('tab');

        if (is_string($legacyTab) && in_array($legacyTab, ContributionResource::legacyTabKeys(), true)) {
            $this->redirect(
                ContributionResource::listTabUrl($legacyTab, cycle: request()->query('cycle')),
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
        if (
            ContributionResource::resolvePrimaryTab() === 'cycle'
            || (ContributionResource::resolvePrimaryTab() === 'ledger' && ContributionResource::resolveLedgerView() === 'arrears')
        ) {
            $this->reconfigureTableForActiveTab();
        }

        ContributionResource::dispatchInsightsRefresh($this);
    }

    public function updatedCycleSegment(): void
    {
        $this->reconfigureTableForActiveTab();
        ContributionResource::dispatchInsightsRefresh($this);
    }

    public function updatedLedgerView(): void
    {
        $this->reconfigureTableForActiveTab();
        ContributionResource::dispatchInsightsRefresh($this);
    }

    protected function makeTable(): Table
    {
        return parent::makeTable();
    }

    public function updatedActiveTab(): void
    {
        if ($this->activeTab !== 'ledger') {
            $this->ledgerView = null;
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

    /**
     * @return array<Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        if (ContributionResource::resolvePrimaryTab() !== 'cycle') {
            return [];
        }

        return [
            CollectionCalendarHeaderAction::make(),
        ];
    }

    public function isTableColumnToggledHidden(string $name): bool
    {
        if (ContributionResource::resolveLedgerView() === 'arrears') {
            return false;
        }

        return parent::isTableColumnToggledHidden($name);
    }

    public function getTableColumnsSessionKey(): string
    {
        $layout = ContributionResource::tableLayoutKey();

        if ($layout !== 'ledger') {
            return 'tables.'.md5(static::class.'|'.$layout).'_columns';
        }

        return parent::getTableColumnsSessionKey();
    }

    public function getHasReorderedTableColumnsSessionKey(): string
    {
        $layout = ContributionResource::tableLayoutKey();

        if ($layout !== 'ledger') {
            return 'tables.'.md5(static::class.'|'.$layout).'_has_reordered_columns';
        }

        return parent::getHasReorderedTableColumnsSessionKey();
    }

    public function getSubheading(): string|Htmlable|null
    {
        $periodLabel = ContributionResource::resolveListCycleLabel();

        if (ContributionResource::resolvePrimaryTab() === 'ledger') {
            return ContributionResource::resolveLedgerView() === 'arrears'
                ? __('Unposted contribution periods after the deadline through :period (since each member joined). Post from the member record or ledger.', [
                    'period' => $periodLabel,
                ])
                : __('Full contribution history, filters, and manual posting. Selected cycle: :period.', [
                    'period' => $periodLabel,
                ]);
        }

        return match (ContributionResource::resolveCycleSegment()) {
            'collected' => __('Contributions already posted for :period.', [
                'period' => $periodLabel,
            ]),
            'arrears' => __('Unposted contribution periods before :period (since each member joined).', [
                'period' => $periodLabel,
            ]),
            default => __('Members who still owe for :period. Apply from cash balance or post manually on the ledger.', [
                'period' => $periodLabel,
            ]),
        };
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                View::make('filament.tenant.resources.contributions.partials.cycle-header'),
                $this->getTabsContentComponent(),
                View::make('filament.tenant.resources.contributions.partials.workspace-subnav-wrapper'),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return [
            ...parent::getPageClasses(),
            'fi-page-collections',
        ];
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

    /**
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return [
            ...parent::getWidgetData(),
            'context' => ContributionResource::resolveInsightsContext(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'cycle' => Tab::make(ContributionResource::listTabLabel('cycle'))
                ->badge(fn (): ?string => $this->cycleTabBadge())
                ->badgeColor('warning'),
            'ledger' => Tab::make(ContributionResource::listTabLabel('ledger'))
                ->badge(fn (): ?string => $this->ledgerArrearsBadge())
                ->badgeColor('danger'),
        ];
    }

    protected function cycleTabBadge(): ?string
    {
        if (ContributionResource::resolveCycleSegment() !== 'collect') {
            return null;
        }

        [$month, $year] = ContributionResource::resolveListCycle();
        $pending = ContributionResource::pendingCountForPeriod($month, $year);

        return $pending > 0 ? (string) $pending : null;
    }

    protected function ledgerArrearsBadge(): ?string
    {
        $count = ContributionResource::contributionArrearsPeriodCount(
            $this->arrearsMemberFilterFromTable(),
        );

        return $count > 0 ? (string) $count : null;
    }

    protected function arrearsMemberFilterFromTable(): ?int
    {
        $value = $this->tableFilters['member_id']['value'] ?? null;

        if (blank($value)) {
            return ContributionResource::memberFilterFromRequest();
        }

        $memberId = (int) $value;

        return $memberId > 0 ? $memberId : null;
    }

    protected function getTableQueryStringIdentifier(): ?string
    {
        return ContributionResource::tableLayoutKey() === 'cycle|collect'
            ? null
            : 'contributions-'.str_replace('|', '-', ContributionResource::tableLayoutKey());
    }
}
