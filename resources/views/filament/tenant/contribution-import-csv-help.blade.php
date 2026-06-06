<div class="space-y-4 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
    <div
        class="rounded-lg border border-blue-200 bg-blue-50/80 p-3 text-xs dark:border-blue-500/30 dark:bg-blue-500/10">
        <p class="font-semibold text-blue-900 dark:text-blue-200 mb-1">{{ __('Before you import') }}</p>
        <p class="mb-1">
            {{ __('Download a sample CSV:') }}
            <a href="{{ route('tenant.downloads.contribution-import-sample') }}"
                class="font-semibold text-blue-700 underline hover:text-blue-600 dark:text-blue-300 dark:hover:text-blue-200">
                contributions-import-sample.csv
            </a>
        </p>
        <p>{{ __('Posted rows credit member and master fund only (no member cash debit). Use membership import cut-off balances for opening cash if needed.') }}
        </p>
    </div>

    <div class="rounded-lg border border-gray-200 overflow-hidden dark:border-white/10">
        <table class="w-full text-xs">
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                <tr>
                    <td
                        class="w-44 bg-gray-50 px-3 py-2 font-semibold text-gray-700 dark:bg-white/5 dark:text-gray-200">
                        {{ __('CSV format') }}
                    </td>
                    <td class="px-3 py-2">{{ __('UTF-8 CSV with a header row. Column order does not matter.') }}</td>
                </tr>
                <tr>
                    <td class="bg-gray-50 px-3 py-2 font-semibold text-gray-700 dark:bg-white/5 dark:text-gray-200">
                        {{ __('Member identifier') }}
                    </td>
                    <td class="px-3 py-2">
                        {{ __('One of member_email, member_number, national_id, or member_name (or name).') }}
                    </td>
                </tr>
                <tr>
                    <td class="bg-gray-50 px-3 py-2 font-semibold text-gray-700 dark:bg-white/5 dark:text-gray-200">
                        {{ __('period') }}
                    </td>
                    <td class="px-3 py-2">{{ __('Required. YYYY-MM or YYYY-MM-DD (first day of month is used).') }}</td>
                </tr>
                <tr>
                    <td class="bg-gray-50 px-3 py-2 font-semibold text-gray-700 dark:bg-white/5 dark:text-gray-200">
                        {{ __('status') }}
                    </td>
                    <td class="px-3 py-2">{{ __('Optional: pending (default), posted, waived, or failed.') }}</td>
                </tr>
                <tr>
                    <td class="bg-gray-50 px-3 py-2 font-semibold text-gray-700 dark:bg-white/5 dark:text-gray-200">
                        {{ __('Amount columns') }}
                    </td>
                    <td class="px-3 py-2">
                        {{ __('amount or amount_due; defaults to the member monthly contribution when omitted.') }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>