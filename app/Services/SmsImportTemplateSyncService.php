<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\SmsImportTemplate;

final class SmsImportTemplateSyncService
{
    /**
     * @param  list<array<string, mixed>>  $templates
     */
    public function syncFromSettingsForm(array $templates): void
    {
        $existingIds = SmsImportTemplate::query()->pluck('id')->all();
        $keptIds = [];

        foreach ($templates as $templateData) {
            $attrs = [
                'bank_name' => filled($templateData['bank_name'] ?? null) ? $templateData['bank_name'] : null,
                'name' => $templateData['name'],
                'is_default' => (bool) ($templateData['is_default'] ?? false),
                'delimiter' => $templateData['delimiter'],
                'encoding' => $templateData['encoding'] ?? 'UTF-8',
                'has_header' => (bool) ($templateData['has_header'] ?? true),
                'skip_rows' => (int) ($templateData['skip_rows'] ?? 0),
                'sms_column' => $templateData['sms_column'],
                'date_column' => filled($templateData['date_column'] ?? null) ? $templateData['date_column'] : null,
                'date_format' => $templateData['date_format'] ?? 'Y-m-d H:i:s',
                'amount_pattern' => $templateData['amount_pattern'] ?? null,
                'date_pattern' => $templateData['date_pattern'] ?? null,
                'date_pattern_format' => $templateData['date_pattern_format'] ?? null,
                'reference_pattern' => $templateData['reference_pattern'] ?? null,
                'credit_keywords' => $templateData['credit_keywords'] ?? ['credited', 'received', 'deposit', 'credit'],
                'debit_keywords' => $templateData['debit_keywords'] ?? ['debited', 'paid', 'purchase', 'debit', 'withdraw'],
                'default_transaction_type' => $templateData['default_transaction_type'] ?? 'credit',
                'duplicate_match_fields' => $templateData['duplicate_match_fields'] ?? ['date', 'amount', 'reference'],
                'duplicate_date_tolerance' => (int) ($templateData['duplicate_date_tolerance'] ?? 0),
                'member_match_pattern' => $templateData['member_match_pattern'] ?? null,
                'member_match_field' => $templateData['member_match_field'] ?? 'member_number',
            ];

            if (!empty($templateData['id'])) {
                $template = SmsImportTemplate::query()->find($templateData['id']);
                if ($template !== null) {
                    $template->update($attrs);
                    $keptIds[] = $template->id;
                } else {
                    $new = SmsImportTemplate::query()->create($attrs);
                    $keptIds[] = $new->id;
                }
            } else {
                $new = SmsImportTemplate::query()->create($attrs);
                $keptIds[] = $new->id;
            }
        }

        $deleteIds = array_diff($existingIds, $keptIds);
        if ($deleteIds !== []) {
            SmsImportTemplate::query()->whereIn('id', $deleteIds)->delete();
        }

        foreach (SmsImportTemplate::query()->where('is_default', true)->get() as $defaultTemplate) {
            SmsImportTemplate::query()
                ->where('id', '!=', $defaultTemplate->id)
                ->when(
                    filled($defaultTemplate->bank_name),
                    fn($query) => $query->where('bank_name', $defaultTemplate->bank_name),
                    fn($query) => $query->whereNull('bank_name'),
                )
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }
    }
}
