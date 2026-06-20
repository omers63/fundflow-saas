<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use App\Filament\Support\BankTransactionImportFields;
use App\Filament\Support\MoneyDisplay;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Setting;
use Filament\Actions\ViewAction;

final class ViewBankTransactionAction
{
    public static function make(): ViewAction
    {
        return TenantPortalViewModal::apply(
            ViewAction::make()
                ->modalHeading(fn (BankTransaction $record): string => filled($record->description)
                    ? $record->description
                    : __('Bank transaction #:id', ['id' => $record->id]))
                ->modalContent(fn (BankTransaction $record) => TenantPortalViewModal::content(
                    self::sections($record),
                )),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function sections(BankTransaction $record): array
    {
        $record->loadMissing(['bankStatement.bankTemplate', 'member', 'duplicateOf']);

        $currency = Setting::get('general', 'currency', 'USD');
        $amount = MoneyDisplay::format((float) $record->amount, $currency) ?? __('—');
        $type = $record->amount >= 0 ? 'credit' : 'debit';

        $statusChip = match ($record->status) {
            'posted' => ['label' => __('Posted'), 'variant' => 'green'],
            'mirrored' => ['label' => __('Mirrored'), 'variant' => 'sky'],
            'imported' => ['label' => __('Imported'), 'variant' => 'amber'],
            'duplicate' => ['label' => __('Duplicate'), 'variant' => 'gray'],
            default => ['label' => ucfirst((string) $record->status), 'variant' => 'gray'],
        };

        $sections = [
            [
                'hero' => [
                    'label' => filled($record->description)
                        ? $record->description
                        : __('Bank transaction #:id', ['id' => $record->id]),
                    'amount' => $amount,
                    'type' => $type,
                    'subtitle' => $record->transaction_date?->format('d M Y') ?? __('—'),
                    'chip' => $statusChip['label'],
                    'chipVariant' => $statusChip['variant'],
                    'chipSecondary' => $record->is_cleared ? __('Cleared') : __('Uncleared'),
                    'chipSecondaryVariant' => $record->is_cleared ? 'green' : 'amber',
                ],
            ],
            [
                'title' => __('Transaction details'),
                'columns' => 3,
                'items' => [
                    ['label' => __('Date'), 'value' => $record->transaction_date?->format('d M Y') ?? __('—')],
                    ['label' => __('Type'), 'value' => ucfirst((string) ($record->transaction_type ?? __('—')))],
                    ['label' => __('Reference'), 'value' => $record->reference ?? __('—')],
                    ['label' => __('Source statement'), 'value' => $record->bankStatement?->filename ?? __('—')],
                    ['label' => __('Import template'), 'value' => $record->bankStatement?->bankTemplate?->name ?? __('Default template')],
                    ['label' => __('Member'), 'value' => $record->member?->name ?? __('Unassigned')],
                    ['label' => __('Cleared at'), 'value' => $record->cleared_at?->format('d M Y H:i') ?? __('—')],
                    ['label' => __('Imported'), 'value' => $record->created_at?->format('d M Y H:i') ?? __('—')],
                    ['label' => __('Master cash mirror'), 'value' => $record->masterCashMirrorSummary() ?? __('Not mirrored yet')],
                ],
            ],
        ];

        if (filled($record->duplicateOf?->description)) {
            $sections[] = [
                'title' => __('Duplicate of'),
                'prose' => $record->duplicateOf->description,
            ];
        }

        $importRows = BankTransactionImportFields::labeledRows($record);

        if ($importRows !== []) {
            $importLines = collect($importRows)
                ->map(fn (mixed $value, string $key): string => "{$key}: ".(is_scalar($value) ? (string) $value : json_encode($value)))
                ->implode("\n");

            $sections[] = [
                'title' => __('Template column mapping'),
                'prose' => $importLines,
            ];
        }

        return $sections;
    }
}
