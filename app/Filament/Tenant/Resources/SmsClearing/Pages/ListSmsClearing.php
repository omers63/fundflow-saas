<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\SmsClearing\Pages;

use App\Filament\Support\BankWorkspaceImportTableHeaderActions;
use App\Filament\Tenant\Concerns\EmbedsAsSmsClearingWorkspacePanel;
use App\Filament\Tenant\Pages\ReconciliationOverviewPage;
use App\Filament\Tenant\Pages\Settings;
use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Resources\MasterAccounts\MasterAccountResource;
use App\Filament\Tenant\Resources\SmsClearing\SmsClearingResource;
use App\Filament\Tenant\Support\SmsClearingTabRegistry;
use App\Models\Tenant\Account;
use App\Models\Tenant\SmsTransaction;
use App\Services\SmsClearingInsightsService;
use App\Services\SmsClearingQueueService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListSmsClearing extends ListRecords
{
    use EmbedsAsSmsClearingWorkspacePanel;

    protected static string $resource = SmsClearingResource::class;

    /** @var 'all'|'unmatched'|'ready_to_post' */
    #[Url(as: 'queueFilter')]
    public string $queueFilter = SmsClearingTabRegistry::FILTER_ALL;

    /** @var 'batches'|'duplicates' */
    #[Url(as: 'historySection')]
    public string $historySection = SmsClearingTabRegistry::HISTORY_BATCHES;

    public bool $showDuplicateHistory = false;

    public function boot(): void
    {
        $this->bootEmbedsAsSmsClearingWorkspacePanel();
    }

    public function mount(): void
    {
        parent::mount();

        $legacySubTab = request()->string('smsSubTab')->toString();

        if (filled($legacySubTab) && ! request()->has('tab')) {
            $this->activeTab = $legacySubTab === 'history'
                ? SmsClearingTabRegistry::TAB_HISTORY
                : SmsClearingTabRegistry::TAB_QUEUE;
        }

        $legacyTab = request()->string('tab')->toString();

        if (filled($legacyTab)) {
            $this->activeTab = SmsClearingTabRegistry::normalizeTab($legacyTab);
        }

        if (! in_array($this->activeTab, array_keys($this->getSmsClearingTabs()), true)) {
            $this->activeTab = SmsClearingTabRegistry::TAB_QUEUE;
        }

        $this->queueFilter = SmsClearingResource::resolveQueueFilter();
        $this->historySection = SmsClearingResource::resolveHistorySection();
        $this->showDuplicateHistory = $this->historySection === SmsClearingTabRegistry::HISTORY_DUPLICATES;

        unset($this->cachedTabs);
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return SmsClearingTabRegistry::TAB_QUEUE;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return match (SmsClearingResource::resolveListSmsClearingTab()) {
            SmsClearingTabRegistry::TAB_LEDGER => __('SMS rows posted to member cash.'),
            SmsClearingTabRegistry::TAB_HISTORY => __('Import batches and duplicate rows for audit.'),
            default => __('Work open SMS imports and post verified rows to member cash.'),
        };
    }

    public function toggleDuplicateHistory(): void
    {
        $this->showDuplicateHistory = ! $this->showDuplicateHistory;
        $this->historySection = $this->showDuplicateHistory
            ? SmsClearingTabRegistry::HISTORY_DUPLICATES
            : SmsClearingTabRegistry::HISTORY_BATCHES;
    }

    /**
     * @return array<string, string>
     */
    public function getSmsClearingTabs(): array
    {
        return SmsClearingTabRegistry::tabs();
    }

    public function setSmsTab(string $tab): void
    {
        $tab = SmsClearingTabRegistry::normalizeTab($tab);

        if (! array_key_exists($tab, $this->getSmsClearingTabs())) {
            return;
        }

        if ($this->activeTab === $tab) {
            return;
        }

        $this->activeTab = $tab;
        $this->tableSort = null;
        $this->reconfigureTableForActiveTab();
        $this->resetTable();
    }

    public function setQueueFilter(string $queueFilter): void
    {
        $queueFilter = SmsClearingTabRegistry::normalizeQueueFilter($queueFilter);

        if ($this->queueFilter === $queueFilter) {
            return;
        }

        $this->queueFilter = $queueFilter;
        $this->reconfigureTableForActiveTab();
        $this->resetTable();
    }

    public function setHistorySection(string $historySection): void
    {
        $historySection = SmsClearingTabRegistry::normalizeHistorySection($historySection);

        $this->historySection = $historySection;
        $this->showDuplicateHistory = $historySection === SmsClearingTabRegistry::HISTORY_DUPLICATES;
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
        return __('Bank SMS clearing');
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return ['fi-page-sms-clearing'];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @return array<int, Action|ActionGroup>
     */
    protected function workspacePanelActions(): array
    {
        $tab = SmsClearingResource::resolveListSmsClearingTab();

        $import = BankWorkspaceImportTableHeaderActions::smsImportAction(
            fn (): mixed => $this->resetTable(),
        )
            ->color('primary');

        if (! in_array($tab, [SmsClearingTabRegistry::TAB_QUEUE, SmsClearingTabRegistry::TAB_HISTORY], true)) {
            return [];
        }

        return [
            $import,
            ActionGroup::make([
                Action::make('open_bank_clearing')
                    ->label(__('Bank clearing'))
                    ->icon('heroicon-o-clipboard-document-list')
                    ->url(BankAccountsResource::getUrl('index')),
                Action::make('open_reconciliation')
                    ->label(__('Reconciliation'))
                    ->icon('heroicon-o-scale')
                    ->url(ReconciliationOverviewPage::getUrl()),
            ])
                ->label(__('More'))
                ->icon('heroicon-o-ellipsis-horizontal')
                ->color('gray')
                ->button()
                ->dropdownPlacement('bottom-end'),
        ];
    }

    /**
     * @return array<int, array{label: string, value: string, sub: string, accent: string, url: string}>
     */
    public function getQueueInsightKpis(): array
    {
        return app(SmsClearingInsightsService::class)->snapshot()['clearing_kpis'] ?? [];
    }

    public function getUnmatchedQueueCount(): int
    {
        return app(SmsClearingQueueService::class)->counts()['unmatched'];
    }

    public function getReadyQueueCount(): int
    {
        return app(SmsClearingQueueService::class)->counts()['ready_to_post'];
    }

    public function getOpenQueueCount(): int
    {
        return app(SmsClearingQueueService::class)->openCount();
    }

    public function getMasterCashUrl(): string
    {
        $masterCash = Account::masterCash();

        if ($masterCash !== null) {
            return MasterAccountResource::getUrl('view', ['record' => $masterCash]);
        }

        return MasterAccountResource::getUrl('index', ['tab' => 'cash']);
    }

    public function getReconciliationUrl(): string
    {
        return ReconciliationOverviewPage::getUrl();
    }

    public function getSmsTemplatesSettingsUrl(): string
    {
        return Settings::getUrl(['settingsTab' => 'sms-templates::tab']);
    }

    public function content(Schema $schema): Schema
    {
        $components = [
            SchemaView::make('filament.tenant.pages.sms-clearing')
                ->viewData(fn (): array => [
                    'smsTab' => SmsClearingResource::resolveListSmsClearingTab(),
                    'queueFilter' => $this->queueFilter,
                ]),
        ];

        if ($this->activeTab !== SmsClearingTabRegistry::TAB_HISTORY) {
            $components[] = RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE);
            $components[] = EmbeddedTable::make();
            $components[] = RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER);
        }

        return $schema->components($components);
    }

    protected function getTableQuery(): Builder
    {
        $queue = app(SmsClearingQueueService::class);

        return match (SmsClearingResource::resolveListSmsClearingTab()) {
            SmsClearingTabRegistry::TAB_LEDGER => SmsTransaction::query()->whereNotNull('posted_at'),
            SmsClearingTabRegistry::TAB_QUEUE => $queue->openItemsQuery(SmsClearingResource::resolveQueueFilter()),
            default => static::getResource()::getEloquentQuery(),
        };
    }
}
