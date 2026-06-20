<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use App\Models\Tenant\BankStatement;
use Filament\Actions\ViewAction;

final class ViewBankStatementAction
{
    public static function make(): ViewAction
    {
        return TenantPortalViewModal::apply(
            ViewAction::make()
                ->modalHeading(fn (BankStatement $record): string => $record->filename)
                ->modalContent(fn (BankStatement $record) => TenantPortalViewModal::content(
                    self::sections($record),
                )),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function sections(BankStatement $record): array
    {
        $record->loadMissing(['bankTemplate', 'importer']);

        $statusChip = match ($record->status) {
            'completed' => 'green',
            'pending' => 'amber',
            'processing' => 'sky',
            'failed' => 'red',
            default => 'gray',
        };

        $sections = [
            [
                'hero' => [
                    'label' => $record->filename,
                    'subtitle' => $record->bank_name ?? __('—'),
                    'chip' => BankStatement::statusOptions()[$record->status] ?? ucfirst((string) $record->status),
                    'chipVariant' => $statusChip,
                    'chipSecondary' => $record->imported_at?->format('d M Y H:i'),
                    'chipSecondaryVariant' => 'gray',
                ],
            ],
            [
                'title' => __('Import summary'),
                'columns' => 3,
                'items' => [
                    ['label' => __('Statement date'), 'value' => $record->statement_date?->format('d M Y') ?? __('—')],
                    ['label' => __('Total rows'), 'value' => (string) ($record->total_rows ?? 0)],
                    ['label' => __('Imported'), 'value' => (string) ($record->imported_rows ?? 0)],
                    ['label' => __('Duplicates'), 'value' => (string) ($record->duplicate_rows ?? 0)],
                    ['label' => __('Template'), 'value' => $record->bankTemplate?->name ?? __('Default template')],
                    ['label' => __('Imported by'), 'value' => $record->importer?->name ?? __('—')],
                ],
            ],
        ];

        if (filled($record->notes)) {
            $sections[] = [
                'title' => __('Notes'),
                'prose' => $record->notes,
            ];
        }

        return $sections;
    }
}
