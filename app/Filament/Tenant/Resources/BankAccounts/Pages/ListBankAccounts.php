<?php

namespace App\Filament\Tenant\Resources\BankAccounts\Pages;

use App\Filament\Support\BankWorkspaceImportTableHeaderActions;
use App\Filament\Support\TabLabelColors;
use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Widgets\BankAccountsInsightsWidget;
use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Transaction;
use App\Services\BankClearingMatchService;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListBankAccounts extends ListRecords
{
    protected static string $resource = BankAccountsResource::class;

    /** @var 'bank'|'sms' */
    #[Url(as: 'channel')]
    public string $channel = 'bank';

    /** @var 'transactions'|'history' */
    #[Url(as: 'smsSubTab')]
    public string $smsSubTab = 'transactions';

    /** @var 'unmatched'|'matched' */
    #[Url(as: 'importsSection')]
    public string $importsSection = 'unmatched';

    public function mount(): void
    {
        parent::mount();

        if (! in_array($this->channel, ['bank', 'sms'], true)) {
            $this->channel = 'bank';
        }

        if (! in_array($this->smsSubTab, ['transactions', 'history'], true)) {
            $this->smsSubTab = 'transactions';
        }

        if (! in_array($this->importsSection, ['unmatched', 'matched'], true)) {
            $this->importsSection = 'unmatched';
        }

        unset($this->cachedTabs);
    }

    public function setImportsSection(string $importsSection): void
    {
        if (! in_array($importsSection, ['unmatched', 'matched'], true)) {
            return;
        }

        if ($this->importsSection === $importsSection) {
            return;
        }

        $this->importsSection = $importsSection;
        $this->resetTable();
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'clearance';
    }

    public function setChannel(string $channel): void
    {
        if (! in_array($channel, ['bank', 'sms'], true)) {
            return;
        }

        $this->channel = $channel;
        unset($this->cachedTabs);

        if ($channel === 'bank') {
            if (blank($this->activeTab) || ! array_key_exists($this->activeTab, $this->getCachedTabs())) {
                $this->activeTab = $this->getDefaultActiveTab();
            }

            $this->reconfigureTableForActiveTab();
        }
    }

    public function updatedChannel(): void
    {
        unset($this->cachedTabs);

        if ($this->channel === 'bank') {
            if (blank($this->activeTab) || ! array_key_exists($this->activeTab, $this->getCachedTabs())) {
                $this->activeTab = $this->getDefaultActiveTab();
            }

            $this->reconfigureTableForActiveTab();
        }
    }

    public function setSmsSubTab(string $smsSubTab): void
    {
        if (! in_array($smsSubTab, ['transactions', 'history'], true)) {
            return;
        }

        $this->smsSubTab = $smsSubTab;
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
        return __('Bank clearing');
    }

    /**
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            BankAccountsInsightsWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        if ($this->channel !== 'bank') {
            return [];
        }

        return match (BankAccountsResource::resolveListBankAccountsTab()) {
            'imports', 'transactions', 'clearance', 'statements' => [
                BankWorkspaceImportTableHeaderActions::bankStatementImportAction(
                    fn (): mixed => $this->resetTable(),
                ),
            ],
            default => [],
        };
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getTabs(): array
    {
        if ($this->channel === 'sms') {
            return [];
        }

        return [
            'clearance' => Tab::make(__('Pending bank match'))
                ->icon(Heroicon::OutlinedLink)
                ->badge(fn (): ?string => ($count = app(BankClearingMatchService::class)->pendingOperationalClearanceCount()) > 0
                    ? (string) $count
                    : null)
                ->extraAttributes(['data-ff-tab-key' => 'clearance', 'data-ff-tab-color' => TabLabelColors::forKey('clearance')], merge: true),
            'imports' => Tab::make(__('Statement lines'))
                ->icon(Heroicon::OutlinedQueueList)
                ->extraAttributes(['data-ff-tab-key' => 'imports', 'data-ff-tab-color' => TabLabelColors::forKey('imports')], merge: true),
            'ledger' => Tab::make(__('Master bank ledger'))
                ->icon(Heroicon::OutlinedBookOpen)
                ->extraAttributes(['data-ff-tab-key' => 'ledger', 'data-ff-tab-color' => TabLabelColors::forKey('ledger')], merge: true),
            'statements' => Tab::make(__('Statements'))
                ->icon(Heroicon::OutlinedDocumentText)
                ->extraAttributes(['data-ff-tab-key' => 'statements', 'data-ff-tab-color' => TabLabelColors::forKey('statements')], merge: true),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            SchemaView::make('filament.tenant.resources.bank-accounts.pages.bank-workspace')
                ->viewData([
                    'channel' => $this->channel,
                    'smsSubTab' => $this->smsSubTab,
                ]),
            ...($this->channel === 'bank'
                ? [
                    $this->getTabsContentComponent(),
                    SchemaView::make('filament.tenant.resources.bank-accounts.partials.imports-section-pills')
                        ->viewData(fn (): array => [
                            'importsSection' => $this->importsSection,
                            'visible' => BankAccountsResource::resolveListBankAccountsTab() === 'imports',
                        ]),
                    RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                    EmbeddedTable::make(),
                    RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
                ]
                : []),
        ]);
    }

    protected function getTableQuery(): Builder
    {
        $masterBankId = Account::masterBank()?->id;

        return match (BankAccountsResource::resolveListBankAccountsTab()) {
            'ledger' => Transaction::query()->when(
                $masterBankId !== null,
                fn (Builder $query): Builder => $query->where('account_id', $masterBankId),
                fn (Builder $query): Builder => $query->whereRaw('0 = 1'),
            ),
            'imports' => tap(
                app(BankClearingMatchService::class)
                    ->applyRealBankStatementLinesScope(BankTransaction::query()),
                function (Builder $query): void {
                    if ($this->importsSection === 'matched') {
                        $query->whereIn('status', ['posted', 'duplicate', 'ignored']);
                    } else {
                        $query->whereIn('status', ['imported', 'mirrored']);
                    }
                },
            ),
            'clearance' => app(BankClearingMatchService::class)
                ->applyPendingOperationalClearanceScope(BankTransaction::query()),
            default => static::getResource()::getEloquentQuery(),
        };
    }
}
