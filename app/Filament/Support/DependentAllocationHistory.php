<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\DependentAllocationChange;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

final class DependentAllocationHistory
{
    public static function modalContent(Member $record): HtmlString
    {
        $currency = Setting::get('general', 'currency', 'USD');

        /** @var Collection<int, DependentAllocationChange> $changes */
        $changes = DependentAllocationChange::query()
            ->where('dependent_member_id', $record->id)
            ->with('changedBy')
            ->latest()
            ->limit(30)
            ->get();

        if ($changes->isEmpty()) {
            return new HtmlString('<p class="text-sm text-gray-500 p-4">'.e(__('No allocation changes recorded.')).'</p>');
        }

        $rows = '';
        foreach ($changes as $change) {
            $dir = $change->isIncrease()
                ? '<span class="text-emerald-600 font-bold">↑</span>'
                : '<span class="text-amber-600 font-bold">↓</span>';
            $delta = $change->isIncrease()
                ? '<span class="text-emerald-600">+'.(MoneyDisplay::html(abs($change->delta()), $currency)?->toHtml() ?? '').'</span>'
                : '<span class="text-amber-600">−'.(MoneyDisplay::html(abs($change->delta()), $currency)?->toHtml() ?? '').'</span>';
            $by = e($change->changedBy?->name ?? __('System'));
            $note = $change->note ? '<br><span class="text-gray-400 text-xs">'.e($change->note).'</span>' : '';
            $date = $change->created_at->locale(app()->getLocale())->translatedFormat('d M Y H:i');

            $changeCell = $dir.' '.(MoneyDisplay::html((float) $change->old_amount, $currency)?->toHtml() ?? '')
                .' → '
                .(MoneyDisplay::html((float) $change->new_amount, $currency)?->toHtml() ?? '');

            $rows .= "
                <tr class=\"border-b border-gray-100 dark:border-gray-700\">
                    <td class=\"py-2 px-3 text-xs text-gray-500\">{$date}</td>
                    <td class=\"py-2 px-3 text-sm\">{$changeCell}</td>
                    <td class=\"py-2 px-3 text-sm\">{$delta}</td>
                    <td class=\"py-2 px-3 text-sm\">{$by}{$note}</td>
                </tr>";
        }

        return new HtmlString('
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-gray-800 text-xs uppercase text-gray-500">
                            <th class="py-2 px-3 text-start">'.e(__('Date')).'</th>
                            <th class="py-2 px-3 text-start">'.e(__('Change')).'</th>
                            <th class="py-2 px-3 text-start">'.e(__('Delta')).'</th>
                            <th class="py-2 px-3 text-start">'.e(__('Changed by'))."</th>
                        </tr>
                    </thead>
                    <tbody>{$rows}</tbody>
                </table>
            </div>");
    }
}
