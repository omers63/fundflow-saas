<?php

namespace App\Filament\Tenant\Resources\BankAccounts\Pages;

use App\Filament\Concerns\OpensFocusedLedgerTransaction;
use App\Filament\Support\BankWorkspaceImportTableHeaderActions;
use App\Filament\Tenant\Concerns\EmbedsAsBankClearingWorkspacePanel;
use App\Filament\Tenant\Pages\ReconciliationOverviewPage;
use App\Filament\Tenant\Pages\Settings;
use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Resources\MasterAccounts\MasterAccountResource;
use App\Filament\Tenant\Resources\SmsClearing\SmsClearingResource;
use App\Filament\Tenant\Support\BankClearingTabRegistry;
use App\Models\Tenant\Account;
use App\Models\Tenant\Transaction;
use App\Services\BankAccountsInsightsService;
use App\Services\BankClearingQueueService;
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

class ListBankAccounts extends ListRecords
{
    use EmbedsAsBankClearingWorkspacePanel;
    use OpensFocusedLedgerTransaction;

    protected static string $resource = BankAccountsResource::class;

    /** @var 'all'|'bank_file'|'operations' */
    #[Url(as: 'queueFilter')]
    public string $queueFilter = BankClearingTabRegistry::FILTER_ALL;

    /** @var 'batches'|'closed' */
    #[Url(as: 'historySection')]
    public string $historySection = BankClearingTabRegistry::HISTORY_BATCHES;

    public bool $showQueueBalances = false;

    public bool $showClosedHistoryLines = false;

    public function boot(): void
    {
        $this->bootEmbedsAsBankClearingWorkspacePanel();
    }

    public function mount(): void
    {
        if (request()->string('channel')->toString() === 'sms') {
            $parameters = [];

            if (request()->string('smsSubTab')->toString() === 'history') {
                $parameters['tab'] = 'history';
            }

            $this->redirect(SmsClearingResource::getUrl('index', $parameters), navigate: true);

            return;
        }

        parent::mount();

        $legacyTab = request()->string('tab')->toString();
        $queueFilterFromQuery = request()->query('queueFilter');
        $hasExplicitQueueFilter = filled($queueFilterFromQuery);
        $legacyQueueFilter = null;

        if (filled($legacyTab)) {
            $this->activeTab = BankClearingTabRegistry::normalizeTab($legacyTab);

            if ($legacyFilter = BankClearingTabRegistry::legacyTabQueueFilter($legacyTab)) {
                $legacyQueueFilter = $legacyFilter;
                $this->queueFilter = $legacyFilter;
            }
        }

        if (! in_array($this->activeTab, array_keys($this->getBankClearingTabs()), true)) {
            $this->activeTab = BankClearingTabRegistry::TAB_QUEUE;
        }

        $this->queueFilter = BankClearingTabRegistry::normalizeQueueFilter($this->queueFilter);

        // Smart Source default only when the URL omitted queueFilter and nothing else
        // already selected a non-default slice (deep links, legacy tabs, Livewire params).
        if (
            $this->activeTab === BankClearingTabRegistry::TAB_QUEUE
            && ! $hasExplicitQueueFilter
            && $legacyQueueFilter === null
            && $this->queueFilter === BankClearingTabRegistry::FILTER_ALL
        ) {
            $this->queueFilter = BankClearingTabRegistry::defaultQueueFilter(
                app(BankClearingQueueService::class)->counts(),
            );
        }

        $this->historySection = BankClearingTabRegistry::normalizeHistorySection($this->historySection);
        $this->showClosedHistoryLines = $this->historySection === BankClearingTabRegistry::HISTORY_CLOSED;

        unset($this->cachedTabs);
    }

    public function bootedOpensFocusedLedgerTransaction(): void
    {
        if ($this->resolveFocusTransactionId() === null || $this->hasAutoOpenedFocusedTransaction) {
            return;
        }

        $this->activeTab = BankClearingTabRegistry::TAB_LEDGER;

        $transactionId = $this->resolveFocusTransactionId();

        if ($transactionId === null || ! $this->focusedLedgerTransactionMatchesContext($transactionId, Account::masterBank()?->id)) {
            return;
        }

        $this->scheduleFocusedLedgerTransactionAction($transactionId);
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return BankClearingTabRegistry::TAB_QUEUE;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return match (BankAccountsResource::resolveListBankAccountsTab()) {
            BankClearingTabRegistry::TAB_LEDGER => __('Posted master bank movements and manual adjustments.'),
            BankClearingTabRegistry::TAB_HISTORY => __('Import batches and closed statement lines for audit.'),
            default => __('Work open imports and operational bank matches from one queue.'),
        };
    }

    public function toggleQueueBalances(): void
    {
        $this->showQueueBalances = ! $this->showQueueBalances;
    }

    public function toggleClosedHistoryLines(): void
    {
        $this->showClosedHistoryLines = ! $this->showClosedHistoryLines;
        $this->historySection = $this->showClosedHistoryLines
            ? BankClearingTabRegistry::HISTORY_CLOSED
            : BankClearingTabRegistry::HISTORY_BATCHES;
    }

    /**
     * @return array<string, string>
     */
    public function getBankClearingTabs(): array
    {
        return BankClearingTabRegistry::tabs();
    }

    public function setBankTab(string $tab): void
    {
        $tab = BankClearingTabRegistry::normalizeTab($tab);

        if (! array_key_exists($tab, $this->getBankClearingTabs())) {
            return;
        }

        if ($this->activeTab === $tab) {
            return;
        }

        $this->activeTab = $tab;
        $this->tableSort = null;
        $this->cachedHeaderWidgetsSchemaComponents = [];
        $this->reconfigureTableForActiveTab();
        $this->resetTable();
    }

    public function setQueueFilter(string $queueFilter): void
    {
        $queueFilter = BankClearingTabRegistry::normalizeQueueFilter($queueFilter);

        if ($this->queueFilter === $queueFilter) {
            return;
        }

        $this->queueFilter = $queueFilter;
        $this->reconfigureTableForActiveTab();
        $this->resetTable();
    }

    public function setHistorySection(string $historySection): void
    {
        $historySection = BankClearingTabRegistry::normalizeHistorySection($historySection);

        $this->historySection = $historySection;
        $this->showClosedHistoryLines = $historySection === BankClearingTabRegistry::HISTORY_CLOSED;
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

        // Column manager keeps Livewire + session state keyed by page class. Queue, ledger,
        // and history define different columns; without a reset, names missing from the prior
        // tab's state are treated as hidden until a full page remount.
        $this->tableColumns = [];
        $this->cachedDefaultTableColumnState = null;
        $this->initTableColumnManager();

        $this->tableFilters = [];
        $this->getTableFiltersForm()->fill([]);
    }

    public function getTableColumnsSessionKey(): string
    {
        return 'tables.'.md5(static::class.'|'.$this->activeTab).'_columns';
    }

    public function getHasReorderedTableColumnsSessionKey(): string
    {
        return 'tables.'.md5(static::class.'|'.$this->activeTab).'_has_reordered_columns';
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
        return __('Bank clearing');
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return ['fi-page-bank-clearing'];
    }

    /**
     * Header widgets are not used: Filament caches them across tabs, and nested widgets
     * cannot reliably read the page activeTab. Tab-specific KPIs live in the workspace blade;
     * the full dashboard is only behind Work queue → Show balances & trends.
     *
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getLedgerInsightKpis(): array
    {
        return app(BankAccountsInsightsService::class)
            ->snapshot(BankClearingTabRegistry::TAB_LEDGER)['ledger_kpis'] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getHistoryInsightKpis(): array
    {
        return app(BankAccountsInsightsService::class)
            ->snapshot(BankClearingTabRegistry::TAB_HISTORY)['history_kpis'] ?? [];
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
        $tab = BankAccountsResource::resolveListBankAccountsTab();

        $import = BankWorkspaceImportTableHeaderActions::bankStatementImportAction(
            fn (): mixed => $this->resetTable(),
        )
            ->color('primary');

        if (! in_array($tab, [BankClearingTabRegistry::TAB_QUEUE, BankClearingTabRegistry::TAB_HISTORY], true)) {
            return [];
        }

        return [
            $import,
            ActionGroup::make([
                Action::make('open_sms_clearing')
                    ->label(__('SMS clearing'))
                    ->icon('heroicon-o-device-phone-mobile')
                    ->url(SmsClearingResource::getUrl('index')),
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
        return app(BankAccountsInsightsService::class)->snapshot()['clearing_kpis'] ?? [];
    }

    public function getBankFileQueueCount(): int
    {
        return app(BankClearingQueueService::class)->counts()['bank_file'];
    }

    public function getOperationsQueueCount(): int
    {
        return app(BankClearingQueueService::class)->counts()['operations'];
    }

    public function getOpenQueueCount(): int
    {
        return app(BankClearingQueueService::class)->openCount();
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

    public function getBankTemplatesSettingsUrl(): string
    {
        return Settings::getUrl(['settingsTab' => 'bank-templates::tab']);
    }

    public function content(Schema $schema): Schema
    {
        $components = [
            SchemaView::make('filament.tenant.pages.bank-clearing')
                ->viewData(fn (): array => [
                    'bankTab' => BankAccountsResource::resolveListBankAccountsTab(),
                    'queueFilter' => $this->queueFilter,
                ]),
        ];

        if ($this->activeTab !== BankClearingTabRegistry::TAB_HISTORY) {
            $components[] = RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE);
            $components[] = EmbeddedTable::make();
            $components[] = RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER);
        }

        return $schema->components($components);
    }

    protected function getTableQuery(): Builder
    {
        $masterBankId = Account::masterBank()?->id;
        $queue = app(BankClearingQueueService::class);

        return match (BankAccountsResource::resolveListBankAccountsTab()) {
            BankClearingTabRegistry::TAB_LEDGER => Transaction::query()->when(
                $masterBankId !== null,
                fn (Builder $query): Builder => $query->where('account_id', $masterBankId),
                fn (Builder $query): Builder => $query->whereRaw('0 = 1'),
            ),
            BankClearingTabRegistry::TAB_QUEUE => $queue->openItemsQuery(BankAccountsResource::resolveQueueFilter()),
            default => static::getResource()::getEloquentQuery(),
        };
    }
}
