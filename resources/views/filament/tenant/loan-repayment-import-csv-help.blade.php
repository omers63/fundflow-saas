<div class="space-y-4 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
    <div
        class="rounded-lg border border-blue-200 bg-blue-50/80 p-3 text-xs dark:border-blue-500/30 dark:bg-blue-500/10">
        <p class="font-semibold text-blue-900 dark:text-blue-200 mb-1">{{ __('Before you import') }}</p>
        <p class="mb-1">
            {{ __('Download a sample CSV:') }}
            <a href="{{ route('tenant.downloads.loan-repayment-import-sample') }}"
                class="font-semibold text-blue-700 underline hover:text-blue-600 dark:text-blue-300 dark:hover:text-blue-200">
                loan-repayments-import-sample.csv
            </a>
        </p>
        <p>{{ __('Legacy rows post fund-side repayments without debiting member cash. Installment rows mark schedule lines paid and post through the normal repayment ledger.') }}
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
                        {{ __('Loan identifier') }}
                    </td>
                    <td class="px-3 py-2">
                        {{ __('loan_number (loan ID) is required. Optional member_email, member_number, national_id, or member_name must match the borrower.') }}
                    </td>
                </tr>
                <tr>
                    <td class="bg-gray-50 px-3 py-2 font-semibold text-gray-700 dark:bg-white/5 dark:text-gray-200">
                        {{ __('repayment_type') }}
                    </td>
                    <td class="px-3 py-2">
                        {{ __('legacy (default) for bulk imported rows, or installment when installment_number is set.') }}
                    </td>
                </tr>
                <tr>
                    <td class="bg-gray-50 px-3 py-2 font-semibold text-gray-700 dark:bg-white/5 dark:text-gray-200">
                        {{ __('Legacy rows') }}
                    </td>
                    <td class="px-3 py-2">
                        {{ __('Provide amount and optional paid_at, notes. Creates an imported repayment row and credits fund accounts.') }}
                    </td>
                </tr>
                <tr>
                    <td class="bg-gray-50 px-3 py-2 font-semibold text-gray-700 dark:bg-white/5 dark:text-gray-200">
                        {{ __('Installment rows') }}
                    </td>
                    <td class="px-3 py-2">
                        {{ __('Provide installment_number and paid_at. amount must match the installment when supplied.') }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>