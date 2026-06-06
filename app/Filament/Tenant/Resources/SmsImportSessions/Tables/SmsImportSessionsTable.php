<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\SmsImportSessions\Tables;

use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Resources\SmsTransactions\SmsTransactionResource;
use App\Models\Tenant\SmsImportSession;
use App\Models\Tenant\SmsImportTemplate;
use App\Models\Tenant\SmsTransaction;
use App\Models\Tenant\User;
use App\Services\SmsImportService;
use App\Support\FilamentStoredUploadPath;
use App\Support\Lang;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

final class SmsImportSessionsTable
{
    public static function configure(Table $table, bool $embedInBankWorkspace = false): Table
    {
        return TableGrouping::apply($table
            ->heading($embedInBankWorkspace ? null : __('SMS import history'))
            ->description($embedInBankWorkspace
                ? __('Monitor SMS import batches with counts, errors, and completion state.')
                : null)
            ->columns([
                TextColumn::make('bank_name')
                    ->label(__('Bank'))
                    ->placeholder(__('—'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('filename')
                    ->searchable()
                    ->limit(40),
                TextColumn::make('template.name')
                    ->label(__('Template'))
                    ->limit(30),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'completed' => 'success',
                        'processing' => 'warning',
                        'partially_completed' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('total_rows')
                    ->label(__('Rows'))
                    ->alignCenter(),
                TextColumn::make('imported_count')
                    ->label(__('Imported'))
                    ->color('success')
                    ->alignCenter(),
                TextColumn::make('duplicate_count')
                    ->label(__('Duplicates'))
                    ->color('warning')
                    ->alignCenter(),
                TextColumn::make('error_count')
                    ->label(__('Errors'))
                    ->color('danger')
                    ->alignCenter(),
                TextColumn::make('importer.name')
                    ->label(__('Imported by')),
                TextColumn::make('created_at')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('bank_name')
                    ->label(__('Bank'))
                    ->options(fn(): array => SmsImportSession::query()
                        ->whereNotNull('bank_name')
                        ->distinct()
                        ->orderBy('bank_name')
                        ->pluck('bank_name', 'bank_name')
                        ->all()),
                SelectFilter::make('template_id')
                    ->label(__('Template'))
                    ->searchable()
                    ->options(fn(): array => SmsImportTemplate::query()
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn(SmsImportTemplate $template): array => [
                            $template->id => trim(($template->bank_name ? $template->bank_name . ' — ' : '') . $template->name),
                        ])
                        ->all()),
                SelectFilter::make('imported_by')
                    ->label(__('Imported by'))
                    ->searchable()
                    ->options(fn(): array => User::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('status')
                    ->options(Lang::transOptions([
                        'pending' => __('Pending'),
                        'processing' => __('Processing'),
                        'completed' => __('Completed'),
                        'partially_completed' => __('Partially completed'),
                        'failed' => __('Failed'),
                    ])),
                Filter::make('imported_between')
                    ->schema([
                        DatePicker::make('from')->label(__('From')),
                        DatePicker::make('until')->label(__('Until')),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn($q, $from) => $q->whereDate('created_at', '>=', $from))
                            ->when($data['until'] ?? null, fn($q, $until) => $q->whereDate('created_at', '<=', $until));
                    }),
                TrashedFilter::make(),
            ])
            ->headerActions([
                Action::make('importSms')
                    ->label(__('Import SMS file'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('primary')
                    ->schema([
                        TextInput::make('bank_name')
                            ->label(__('Bank name (optional)'))
                            ->maxLength(100),
                        Select::make('template_id')
                            ->label(__('SMS template'))
                            ->options(fn(): array => SmsImportTemplate::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->required()
                            ->live()
                            ->helperText(__('Configure templates under Settings → SMS Templates.')),
                        FileUpload::make('csv_file')
                            ->label(__('CSV / text file'))
                            ->disk('local')
                            ->directory('sms-imports')
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                            ->required(),
                        Textarea::make('notes')
                            ->label(__('Notes (optional)'))
                            ->rows(2),
                    ])
                    ->action(function (array $data): void {
                        $resolved = FilamentStoredUploadPath::tryResolveReadableCsvToAbsolutePath($data['csv_file'] ?? null);

                        if ($resolved === null) {
                            Notification::make()
                                ->title(__('Import failed'))
                                ->body(__('No readable file was found. Re-upload and try again.'))
                                ->danger()
                                ->send();

                            return;
                        }

                        $template = SmsImportTemplate::query()->findOrFail($data['template_id']);
                        $relativePath = $resolved['relativePathForDeletion'] ?? str_replace(Storage::disk('local')->path(''), '', $resolved['absolutePath']);

                        $session = SmsImportSession::query()->create([
                            'bank_name' => filled($data['bank_name'] ?? null)
                                ? $data['bank_name']
                                : $template->bank_name,
                            'template_id' => $template->id,
                            'imported_by' => auth('tenant')->id(),
                            'filename' => basename((string) $relativePath),
                            'file_path' => ltrim((string) $relativePath, '/'),
                            'notes' => $data['notes'] ?? null,
                            'status' => 'pending',
                        ]);

                        app(SmsImportService::class)->import($session);
                        $session->refresh();

                        Notification::make()
                            ->title(__('SMS import :status', ['status' => ucfirst(str_replace('_', ' ', $session->status))]))
                            ->body(__('Imported: :imported | Duplicates: :duplicates | Errors: :errors', [
                                'imported' => $session->imported_count,
                                'duplicates' => $session->duplicate_count,
                                'errors' => $session->error_count,
                            ]))
                            ->color($session->status === 'completed' ? 'success' : 'warning')
                            ->send();
                    }),
            ])
            ->recordActions(TableRecordActionGroups::wrap([
                ViewAction::make(),
                Action::make('viewTransactions')
                    ->label(__('Transactions'))
                    ->icon('heroicon-o-table-cells')
                    ->url(fn(SmsImportSession $record): string => SmsTransactionResource::getUrl('index', [
                        'tableFilters' => [
                            'import_session_id' => ['value' => $record->id],
                        ],
                    ])),
                Action::make('retry')
                    ->label(__('Re-import'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn(SmsImportSession $record): bool => in_array($record->status, ['failed', 'partially_completed'], true))
                    ->requiresConfirmation()
                    ->action(function (SmsImportSession $record): void {
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
                    }),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ]), TableGrouping::smsImportSessions());
    }
}
