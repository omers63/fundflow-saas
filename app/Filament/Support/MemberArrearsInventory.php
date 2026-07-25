<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanDelinquencyService;
use Illuminate\Support\HtmlString;

/**
 * Modal inventory of a member's outstanding contribution and loan EMI arrears.
 */
final class MemberArrearsInventory
{
    public static function modalContent(Member $record): HtmlString
    {
        $currency = Setting::get('general', 'currency', 'USD');
        $rows = app(LoanDelinquencyService::class)->memberArrearsInventory($record);

        if ($rows === []) {
            return new HtmlString(
                '<p class="text-sm text-gray-500 p-4">'.e(__('No outstanding arrears for this member.')).'</p>'
            );
        }

        $body = '';

        foreach ($rows as $row) {
            $amount = MoneyDisplay::html((float) $row['amount'], $currency)?->toHtml() ?? e(number_format((float) $row['amount'], 2));
            $type = e((string) $row['type_label']);
            $period = e((string) $row['period_label']);
            $detail = e((string) $row['detail']);
            $status = e((string) $row['status_label']);

            $body .= "
                <tr class=\"border-b border-gray-100 dark:border-gray-700\">
                    <td class=\"py-2 px-3 text-sm\">{$type}</td>
                    <td class=\"py-2 px-3 text-sm\">{$period}</td>
                    <td class=\"py-2 px-3 text-sm text-gray-600 dark:text-gray-300\">{$detail}</td>
                    <td class=\"py-2 px-3 text-sm tabular-nums text-end\">{$amount}</td>
                    <td class=\"py-2 px-3 text-sm\">{$status}</td>
                </tr>";
        }

        return new HtmlString('
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-gray-800 text-xs uppercase text-gray-500">
                            <th class="py-2 px-3 text-start">'.e(__('Type')).'</th>
                            <th class="py-2 px-3 text-start">'.e(__('Cycle')).'</th>
                            <th class="py-2 px-3 text-start">'.e(__('Detail')).'</th>
                            <th class="py-2 px-3 text-end">'.e(__('Amount')).'</th>
                            <th class="py-2 px-3 text-start">'.e(__('Status')).'</th>
                        </tr>
                    </thead>
                    <tbody>'.$body.'</tbody>
                </table>
            </div>');
    }
}
