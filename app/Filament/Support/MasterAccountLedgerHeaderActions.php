<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\Account;
use App\Services\AccountTransactionExportService;
use App\Services\AccountTransactionImportService;
use App\Support\FilamentStoredUploadPath;
use App\Support\MasterReserveLedgerDirection;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Livewire\Component;

/**
 * Import / export and toolbar styling for master account ledger tables.
 */
final class MasterAccountLedgerHeaderActions
{
    /**
     * @param  Closure(): Account  $resolveAccount
     * @param  (Closure(): mixed)|null  $after
     * @return list<Action>
     */
    public static function importExport(Closure $resolveAccount, ?Closure $after = null): array
    {
        $import = self::importAction($resolveAccount, $after);
        $export = self::exportAction($resolveAccount, $after);

        return [$import, $export];
    }

    /**
     * @param  list<Action>  $actions
     * @return list<Action>
     */
    public static function wrap(array $actions): array
    {
        return LedgerToolbarAction::applyMany($actions);
    }

    /**
     * @param  Closure(): Account  $resolveAccount
     */
    private static function importAction(Closure $resolveAccount, ?Closure $after): Action
    {
        $action = Action::make('importLedger')
            ->label(__('Import'))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('success')
            ->visible(fn (): bool => (bool) Auth::guard('tenant')->user()?->is_admin)
            ->modalHeading(__('Import ledger from CSV'))
            ->modalDescription(function () use ($resolveAccount): HtmlString {
                return new HtmlString(
                    view('filament.tenant.master-ledger-import-csv-help', [
                        'account' => $resolveAccount(),
                    ])->render()
                );
            })
            ->modalWidth('2xl')
            ->schema([
                FileUpload::make('csv_file')
                    ->label(__('CSV file'))
                    ->disk('local')
                    ->directory('master-ledger-imports')
                    ->maxFiles(1)
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                    ->helperText(function () use ($resolveAccount): string {
                        return MasterReserveLedgerDirection::isReserveLedger($resolveAccount())
                            ? __('Each row posts a credit or debit using the same workflows as the ledger actions. Transaction date is applied from transacted_at (or transaction_date / date).')
                            : __('Each row posts a manual credit or debit on this master account only. Transaction date is applied from transacted_at (or transaction_date / date).');
                    })
                    ->required(),
            ])
            ->action(function (array $data, Component $livewire) use ($resolveAccount, $after): void {
                $account = $resolveAccount();
                $mounted = collect($livewire->mountedActions ?? [])->last();
                $mountedData = is_array($mounted) ? ($mounted['data'] ?? []) : [];
                $csvRaw = $data['csv_file'] ?? $mountedData['csv_file'] ?? null;

                try {
                    $resolved = FilamentStoredUploadPath::tryResolveReadableCsvToAbsolutePath($csvRaw);

                    if ($resolved === null) {
                        Notification::make()
                            ->title(__('Import failed'))
                            ->body(__('No readable CSV file was found. Re-upload the file, wait until it finishes uploading, then submit.'))
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    $fullPath = $resolved['absolutePath'];
                    $deleteRelative = $resolved['relativePathForDeletion'];

                    try {
                        $result = app(AccountTransactionImportService::class)->import($account, $fullPath);
                    } finally {
                        if ($deleteRelative !== null) {
                            try {
                                Storage::disk('local')->delete($deleteRelative);
                            } catch (\Throwable) {
                            }
                        }
                    }

                    $body = __('Created: :created · Skipped: :skipped · Failed: :failed', [
                        'created' => $result['created'],
                        'skipped' => $result['skipped'] ?? 0,
                        'failed' => $result['failed'],
                    ]);

                    if ($result['errors'] !== []) {
                        $previewLines = array_slice($result['errors'], 0, 8);
                        $preview = implode("\n", $previewLines);

                        if (count($result['errors']) > 8) {
                            $preview .= "\n… ".__('and :count more (see storage/logs/laravel.log)', [
                                'count' => count($result['errors']) - 8,
                            ]);
                        }

                        $body .= "\n\n".$preview;
                    }

                    $livewire->resetTable();
                    $after?->__invoke();
                    AccountDetailInsightsRefresh::dispatch($livewire, (int) $account->getKey());

                    Notification::make()
                        ->title(__('Ledger import finished'))
                        ->body(nl2br(e($body)))
                        ->color($result['failed'] > 0 || $result['errors'] !== [] ? 'warning' : 'success')
                        ->persistent()
                        ->send();
                } catch (\Throwable $e) {
                    report($e);

                    Notification::make()
                        ->title(__('Import failed'))
                        ->body($e->getMessage() !== '' ? $e->getMessage() : __('An unexpected error occurred during import.'))
                        ->danger()
                        ->persistent()
                        ->send();
                }
            });

        if ($after !== null) {
            $action->after($after);
        }

        return LedgerToolbarAction::apply($action);
    }

    /**
     * @param  Closure(): Account  $resolveAccount
     */
    private static function exportAction(Closure $resolveAccount, ?Closure $after): Action
    {
        $action = Action::make('exportLedger')
            ->label(__('Export'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('warning')
            ->visible(fn (): bool => (bool) Auth::guard('tenant')->user()?->is_admin)
            ->action(fn (): mixed => app(AccountTransactionExportService::class)->downloadCsv($resolveAccount()));

        if ($after !== null) {
            $action->after($after);
        }

        return LedgerToolbarAction::apply($action);
    }
}
