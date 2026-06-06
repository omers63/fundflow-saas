<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\SmsImportSessions\Pages;

use App\Filament\Tenant\Resources\SmsImportSessions\SmsImportSessionResource;
use App\Filament\Tenant\Resources\SmsTransactions\SmsTransactionResource;
use App\Models\Tenant\SmsImportSession;
use App\Models\Tenant\SmsTransaction;
use App\Services\SmsImportService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewSmsImportSession extends ViewRecord
{
    protected static string $resource = SmsImportSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewTransactions')
                ->label(__('Transactions'))
                ->icon('heroicon-o-table-cells')
                ->url(fn (): string => SmsTransactionResource::getUrl('index', [
                    'tableFilters' => [
                        'import_session_id' => ['value' => $this->getRecord()->getKey()],
                    ],
                ])),
            Action::make('retry')
                ->label(__('Re-import'))
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => in_array($this->getRecord()->status, ['failed', 'partially_completed'], true))
                ->requiresConfirmation()
                ->action(function (): void {
                    /** @var SmsImportSession $record */
                    $record = $this->getRecord();

                    SmsTransaction::query()
                        ->where('import_session_id', $record->id)
                        ->forceDelete();

                    $record->update([
                        'status' => 'pending',
                        'imported_count' => 0,
                        'duplicate_count' => 0,
                        'error_count' => 0,
                        'error_log' => null,
                    ]);

                    app(SmsImportService::class)->import($record);
                    $record->refresh();

                    Notification::make()
                        ->title(__('Re-import :status', ['status' => ucfirst(str_replace('_', ' ', $record->status))]))
                        ->body(__('Imported: :imported | Duplicates: :duplicates', [
                            'imported' => $record->imported_count,
                            'duplicates' => $record->duplicate_count,
                        ]))
                        ->success()
                        ->send();

                    $this->refreshFormData([
                        'status',
                        'imported_count',
                        'duplicate_count',
                        'error_count',
                        'error_log',
                    ]);
                }),
        ];
    }
}
