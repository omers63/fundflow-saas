<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use App\Filament\Support\MoneyDisplay;
use App\Models\Tenant\Setting;
use App\Models\Tenant\SmsTransaction;
use Filament\Actions\ViewAction;

final class ViewSmsTransactionAction
{
    public static function make(): ViewAction
    {
        return TenantPortalViewModal::apply(
            ViewAction::make()
                ->modalHeading(fn (SmsTransaction $record): string => __('SMS transaction'))
                ->modalContent(fn (SmsTransaction $record) => TenantPortalViewModal::content(
                    self::sections($record),
                )),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function sections(SmsTransaction $record): array
    {
        $currency = Setting::get('general', 'currency', 'USD');
        $amount = MoneyDisplay::format((float) $record->amount, $currency) ?? '—';
        $type = $record->transaction_type === 'credit' ? 'credit' : 'debit';

        $sections = [
            [
                'hero' => [
                    'label' => $record->bank_name ?? __('Unknown bank'),
                    'amount' => $amount,
                    'type' => $type,
                    'subtitle' => $record->transaction_date?->format('d M Y') ?? __('—'),
                    'chip' => $record->isPosted() ? __('Posted') : __('Unposted'),
                    'chipVariant' => $record->isPosted() ? 'green' : 'amber',
                    'chipSecondary' => $record->is_duplicate ? __('Duplicate') : null,
                    'chipSecondaryVariant' => $record->is_duplicate ? 'amber' : 'gray',
                ],
            ],
            [
                'title' => __('Transaction details'),
                'columns' => 3,
                'items' => [
                    ['label' => __('Bank'), 'value' => $record->bank_name ?? __('—')],
                    ['label' => __('Date'), 'value' => $record->transaction_date?->format('d M Y') ?? __('—')],
                    ['label' => __('Amount'), 'value' => $amount],
                    ['label' => __('Type'), 'value' => ucfirst((string) $record->transaction_type)],
                    ['label' => __('Reference'), 'value' => $record->reference ?? __('—')],
                    ['label' => __('Member'), 'value' => $record->member?->name ?? __('Not matched')],
                    ['label' => __('Posted at'), 'value' => $record->posted_at?->format('d M Y H:i') ?? __('Not posted')],
                    ['label' => __('Posted by'), 'value' => $record->postedBy?->name ?? __('—')],
                    ['label' => __('Import session'), 'value' => $record->importSession?->filename ?? __('—')],
                ],
            ],
            [
                'title' => __('Original SMS text'),
                'prose' => $record->raw_sms ?? __('—'),
            ],
        ];

        if (filled($record->raw_data)) {
            $rawData = collect($record->raw_data)
                ->map(fn (mixed $value, string $key): string => "{$key}: ".(is_scalar($value) ? (string) $value : json_encode($value)))
                ->implode("\n");

            $sections[] = [
                'title' => __('Raw CSV row'),
                'prose' => $rawData,
            ];
        }

        return $sections;
    }
}
