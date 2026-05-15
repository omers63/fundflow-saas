<?php

namespace App\Filament\Support;

use App\Models\Tenant\BankTemplate;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Setting;
use Illuminate\Support\Str;

final class BankTransactionImportFields
{
    /**
     * Labeled values for every column mapped by the import template (for display in modals).
     *
     * @return array<string, string>
     */
    public static function labeledRows(BankTransaction $transaction): array
    {
        $transaction->loadMissing('bankStatement.bankTemplate');

        $template = $transaction->bankStatement?->bankTemplate ?? BankTemplate::getDefault();
        $raw = self::decodeRawData($transaction);

        $rows = [];

        if ($template !== null) {
            $rows[__('Date')] = $transaction->transaction_date?->format('M j, Y') ?? '—';

            if ($template->amount_mode === 'split') {
                $credit = $raw['credit'] ?? null;
                $debit = $raw['debit'] ?? null;

                if ($credit !== null && $credit !== '') {
                    $rows[__('Credit column')] = (string) $credit;
                }

                if ($debit !== null && $debit !== '') {
                    $rows[__('Debit column')] = (string) $debit;
                }

                $rows[__('Net amount')] = MoneyDisplay::format(
                    (float) $transaction->amount,
                    Setting::get('general', 'currency', 'USD'),
                );
            } else {
                $rows[__('Amount')] = MoneyDisplay::format(
                    (float) $transaction->amount,
                    Setting::get('general', 'currency', 'USD'),
                );
            }

            foreach ($template->extra_columns ?? [] as $mapping) {
                $key = $mapping['key'] ?? null;

                if (blank($key)) {
                    continue;
                }

                $rows[self::labelForKey($key)] = self::valueForMappedKey($transaction, $raw, (string) $key);
            }
        }

        foreach ($raw as $key => $value) {
            if ($key === '_raw_csv' || array_key_exists(self::labelForKey($key), $rows)) {
                continue;
            }

            if (is_array($value)) {
                continue;
            }

            $rows[self::labelForKey($key)] = blank($value) ? '—' : (string) $value;
        }

        if (isset($raw['_raw_csv']) && is_array($raw['_raw_csv'])) {
            $rows[__('Raw CSV row')] = implode(' | ', array_map(
                fn (mixed $cell): string => trim((string) $cell),
                $raw['_raw_csv'],
            ));
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeRawData(BankTransaction $transaction): array
    {
        if (blank($transaction->raw_data)) {
            return [];
        }

        $decoded = json_decode($transaction->raw_data, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function labelForKey(string $key): string
    {
        return match ($key) {
            'description' => __('Description'),
            'reference' => __('Reference'),
            'type' => __('Type'),
            'balance' => __('Balance'),
            default => Str::headline(str_replace('_', ' ', $key)),
        };
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private static function valueForMappedKey(BankTransaction $transaction, array $raw, string $key): string
    {
        if (array_key_exists($key, $raw) && ! is_array($raw[$key])) {
            $fromRaw = trim((string) $raw[$key]);

            if ($fromRaw !== '') {
                return $fromRaw;
            }
        }

        return match ($key) {
            'description' => filled($transaction->description) ? $transaction->description : '—',
            'reference' => filled($transaction->reference) ? $transaction->reference : '—',
            'type' => filled($transaction->transaction_type) ? $transaction->transaction_type : '—',
            'balance' => array_key_exists('balance', $raw) && is_numeric($raw['balance'])
                ? MoneyDisplay::format((float) $raw['balance'], Setting::get('general', 'currency', 'USD'))
                : '—',
            default => '—',
        };
    }
}
