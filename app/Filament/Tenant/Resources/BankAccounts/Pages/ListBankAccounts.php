<?php

namespace App\Filament\Tenant\Resources\BankAccounts\Pages;

use App\Filament\Support\TabLabelColors;
use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Widgets\BankAccountsInsightsWidget;
use App\Models\Tenant\Account;
use App\Models\Tenant\BankTemplate;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Transaction;
use App\Services\BankClearingMatchService;
use App\Services\BankImportService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ListBankAccounts extends ListRecords
{
    protected static string $resource = BankAccountsResource::class;

    public function updatedActiveTab(): void
    {
        $this->tableSort = null;
        $this->reconfigureTableForActiveTab();
        $this->refreshCachedHeaderActions();

        parent::updatedActiveTab();
    }

    protected function refreshCachedHeaderActions(): void
    {
        $this->cachedHeaderActions = [];

        $this->cacheInteractsWithHeaderActions();
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
        return __('Bank Accounts');
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

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getTabs(): array
    {
        return [
            'imports' => Tab::make(__('Statement lines'))
                ->icon(Heroicon::OutlinedQueueList)
                ->extraAttributes(['data-ff-tab-key' => 'imports', 'data-ff-tab-color' => TabLabelColors::forKey('imports')], merge: true),
            'clearance' => Tab::make(__('Pending bank match'))
                ->icon(Heroicon::OutlinedLink)
                ->badge(fn (): ?string => ($count = app(BankClearingMatchService::class)->pendingOperationalClearanceCount()) > 0
                    ? (string) $count
                    : null)
                ->extraAttributes(['data-ff-tab-key' => 'clearance', 'data-ff-tab-color' => TabLabelColors::forKey('clearance')], merge: true),
            'ledger' => Tab::make(__('Master bank ledger'))
                ->icon(Heroicon::OutlinedBookOpen)
                ->extraAttributes(['data-ff-tab-key' => 'ledger', 'data-ff-tab-color' => TabLabelColors::forKey('ledger')], merge: true),
            'statements' => Tab::make(__('Statements'))
                ->icon(Heroicon::OutlinedDocumentText)
                ->extraAttributes(['data-ff-tab-key' => 'statements', 'data-ff-tab-color' => TabLabelColors::forKey('statements')], merge: true),
        ];
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
            'imports' => app(BankClearingMatchService::class)
                ->applyRealBankStatementLinesScope(BankTransaction::query()),
            'clearance' => app(BankClearingMatchService::class)
                ->applyPendingOperationalClearanceScope(BankTransaction::query()),
            default => static::getResource()::getEloquentQuery(),
        };
    }

    protected function getHeaderActions(): array
    {
        if (BankAccountsResource::resolveListBankAccountsTab() !== 'imports') {
            return [];
        }

        return [
            Action::make('import')
                ->label(__('Import statement'))
                ->icon('heroicon-o-document-arrow-up')
                ->color('primary')
                ->form([
                    FileUpload::make('csv_file')
                        ->label(__('CSV file'))
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                        ->required()
                        ->disk('public')
                        ->directory('bank-imports')
                        ->storeFileNamesIn('original_filename'),
                    Select::make('bank_template_id')
                        ->label(__('Template'))
                        ->options(fn () => BankTemplate::orderBy('name')->pluck('name', 'id'))
                        ->default(fn () => BankTemplate::getDefault()?->id)
                        ->required()
                        ->helperText(__('Select the CSV template that matches this bank statement format.')),
                    TextInput::make('bank_name')
                        ->label(__('Bank name'))
                        ->placeholder(__('e.g. First National Bank')),
                ])
                ->action(function (array $data, BankImportService $service) {
                    $filePath = Storage::disk('public')->path($data['csv_file']);

                    $file = new UploadedFile(
                        $filePath,
                        $data['original_filename'] ?? basename($data['csv_file']),
                    );

                    $template = BankTemplate::findOrFail($data['bank_template_id']);

                    $result = $service->importCsv(
                        file: $file,
                        importedBy: auth()->id(),
                        bankName: $data['bank_name'] ?? null,
                        template: $template->toTemplateArray(),
                        bankTemplateId: $template->id,
                    );

                    $msg = __(':count transaction(s) imported', ['count' => $result['imported']]);
                    if ($result['duplicates'] > 0) {
                        $msg .= ' '.__(':dup duplicate(s) skipped', ['dup' => $result['duplicates']]);
                    }

                    Notification::make()
                        ->title(__('Import complete'))
                        ->body($msg)
                        ->success()
                        ->send();
                }),
        ];
    }
}
