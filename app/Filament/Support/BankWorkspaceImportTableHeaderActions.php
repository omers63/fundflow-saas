<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\BankTemplate;
use App\Models\Tenant\SmsImportTemplate;
use App\Services\BankImportService;
use App\Services\SmsImportService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

final class BankWorkspaceImportTableHeaderActions
{
    public static function bankStatementImportAction(?Closure $after = null): Action
    {
        return Action::make('import')
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
            ->action(function (array $data, BankImportService $service, Component $livewire) use ($after): void {
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

                $after?->__invoke();
                $livewire->resetTable();
            });
    }

    public static function smsImportAction(?Closure $after = null): Action
    {
        return Action::make('importSms')
            ->label(__('Import SMS file'))
            ->icon('heroicon-o-document-arrow-up')
            ->color('primary')
            ->form([
                FileUpload::make('csv_file')
                    ->label(__('CSV file'))
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                    ->required()
                    ->disk('public')
                    ->directory('sms-imports')
                    ->storeFileNamesIn('original_filename'),
                Select::make('sms_template_id')
                    ->label(__('Template'))
                    ->options(fn () => SmsImportTemplate::orderBy('name')->pluck('name', 'id'))
                    ->default(fn () => SmsImportTemplate::getDefault()?->id)
                    ->required()
                    ->helperText(__('Configure templates under Settings → SMS Templates.')),
                TextInput::make('bank_name')
                    ->label(__('Bank name'))
                    ->placeholder(__('e.g. First National Bank')),
            ])
            ->action(function (array $data, SmsImportService $service, Component $livewire) use ($after): void {
                $filePath = Storage::disk('public')->path($data['csv_file']);

                $file = new UploadedFile(
                    $filePath,
                    $data['original_filename'] ?? basename($data['csv_file']),
                );

                $result = $service->importCsv(
                    file: $file,
                    relativeStoragePath: $data['csv_file'],
                    importedBy: auth()->id(),
                    bankName: $data['bank_name'] ?? null,
                    templateId: (int) $data['sms_template_id'],
                );

                $msg = __(':count transaction(s) imported', ['count' => $result['imported']]);
                if ($result['posted'] > 0) {
                    $msg .= ' '.__(':posted posted to member cash', ['posted' => $result['posted']]);
                }
                if ($result['duplicates'] > 0) {
                    $msg .= ' '.__(':dup duplicate(s) skipped', ['dup' => $result['duplicates']]);
                }

                Notification::make()
                    ->title(__('Import complete'))
                    ->body($msg)
                    ->success()
                    ->send();

                $after?->__invoke();
                $livewire->resetTable();
            });
    }
}
