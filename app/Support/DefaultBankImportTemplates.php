<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\BankTemplate;
use Illuminate\Support\Facades\Schema;

/**
 * Default bank CSV import templates (Settings → Bank templates) for new tenants.
 */
final class DefaultBankImportTemplates
{
    /**
     * Ensure the Generic CSV and Al Rajhi Bank templates exist without overwriting edits.
     */
    public static function seedIfMissing(): void
    {
        if (! Schema::hasTable('bank_templates')) {
            return;
        }

        BankTemplate::firstOrCreate(
            ['name' => 'Generic CSV'],
            [
                'encoding' => 'UTF-8',
                'delimiter' => ',',
                'has_header' => true,
                'skip_rows' => 0,
                'date_format' => 'Y-m-d',
                'date_column' => '0',
                'amount_column' => '2',
                'amount_mode' => 'single',
                'credit_column' => null,
                'debit_column' => null,
                'extra_columns' => BankTemplate::defaultExtraColumns(),
                'duplicate_fields' => ['date', 'amount', 'description', 'reference'],
                'duplicate_date_tolerance' => 0,
                'is_default' => false,
            ],
        );

        BankTemplate::firstOrCreate(
            ['name' => 'Al Rajhi Bank'],
            [
                'encoding' => 'UTF-8',
                'delimiter' => ',',
                'has_header' => true,
                'skip_rows' => 15,
                'date_format' => ['d-m-Y', 'd/m/Y'],
                'date_column' => 'التاريخ الميلادي',
                'amount_column' => null,
                'amount_mode' => 'split',
                'credit_column' => 'دائن',
                'debit_column' => 'مدين',
                'extra_columns' => [
                    ['key' => 'البيان', 'column' => 'البيان'],
                    ['key' => 'ملاحظات', 'column' => 'ملاحظات'],
                    ['key' => 'تصنيف العملية', 'column' => 'تصنيف العملية'],
                    ['key' => 'التاريخ الهجري', 'column' => 'التاريخ الهجري'],
                    ['key' => 'الرصيد', 'column' => 'الرصيد'],
                ],
                'duplicate_fields' => ['date', 'amount', 'البيان', 'ملاحظات', 'تصنيف العملية', 'التاريخ الهجري', 'الرصيد'],
                'duplicate_date_tolerance' => 0,
                'is_default' => true,
            ],
        );
    }
}
