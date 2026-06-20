<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use App\Models\Tenant\SmsImportSession;
use Filament\Actions\ViewAction;

final class ViewSmsImportSessionAction
{
    public static function make(): ViewAction
    {
        return TenantPortalViewModal::apply(
            ViewAction::make()
                ->modalHeading(fn (SmsImportSession $record): string => __('SMS import session'))
                ->modalContent(fn (SmsImportSession $record) => TenantPortalViewModal::content(
                    self::sections($record),
                )),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function sections(SmsImportSession $record): array
    {
        $statusLabel = ucfirst(str_replace('_', ' ', (string) $record->status));
        $chipVariant = match ($record->status) {
            'completed' => 'green',
            'processing', 'partially_completed' => 'amber',
            'failed' => 'red',
            default => 'gray',
        };

        $sections = [
            [
                'hero' => [
                    'label' => $record->filename ?? __('Import session'),
                    'subtitle' => $record->bank_name ?? __('—'),
                    'chip' => $statusLabel,
                    'chipVariant' => $chipVariant,
                    'chipSecondary' => $record->template?->name,
                    'chipSecondaryVariant' => 'gray',
                ],
            ],
            [
                'title' => __('Import details'),
                'columns' => 3,
                'items' => [
                    ['label' => __('Bank'), 'value' => $record->bank_name ?? __('—')],
                    ['label' => __('Filename'), 'value' => $record->filename ?? __('—')],
                    ['label' => __('Template'), 'value' => $record->template?->name ?? __('—')],
                    ['label' => __('Status'), 'value' => $statusLabel],
                    ['label' => __('Rows'), 'value' => (string) ($record->total_rows ?? 0)],
                    ['label' => __('Imported'), 'value' => (string) ($record->imported_count ?? 0)],
                    ['label' => __('Duplicates'), 'value' => (string) ($record->duplicate_count ?? 0)],
                    ['label' => __('Errors'), 'value' => (string) ($record->error_count ?? 0)],
                    ['label' => __('Imported by'), 'value' => $record->importer?->name ?? __('—')],
                    ['label' => __('Started'), 'value' => $record->created_at?->format('d M Y H:i') ?? __('—')],
                    ['label' => __('Completed'), 'value' => $record->completed_at?->format('d M Y H:i') ?? __('—')],
                ],
            ],
        ];

        if (filled($record->notes)) {
            $sections[] = [
                'title' => __('Notes'),
                'prose' => $record->notes,
            ];
        }

        if (filled($record->error_log)) {
            $errorLog = collect($record->error_log)
                ->map(fn (mixed $value, string|int $key): string => is_int($key)
                    ? (is_scalar($value) ? (string) $value : json_encode($value))
                    : "{$key}: ".(is_scalar($value) ? (string) $value : json_encode($value)))
                ->implode("\n");

            $sections[] = [
                'title' => __('Error log'),
                'prose' => $errorLog,
            ];
        }

        return $sections;
    }
}
