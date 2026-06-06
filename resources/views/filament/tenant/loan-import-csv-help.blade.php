<div class="space-y-4 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
    <div
        class="rounded-lg border border-blue-200 bg-blue-50/80 p-3 text-xs dark:border-blue-500/30 dark:bg-blue-500/10">
        <p class="font-semibold text-blue-900 dark:text-blue-200 mb-1">{{ __('Before you import') }}</p>
        <p class="mb-1">
            {{ __('Download a sample CSV with varied loan statuses:') }}
            <a href="{{ route('tenant.downloads.loan-import-sample') }}"
                class="font-semibold text-blue-700 underline hover:text-blue-600 dark:text-blue-300 dark:hover:text-blue-200">
                loans-import-sample-10.csv
            </a>
        </p>
        <p>{{ __('If members already have opening cash or fund balances from a membership import, disbursed rows will post additional ledger entries — reconcile totals before go-live.') }}
        </p>
    </div>

    <div class="rounded-lg border border-gray-200 overflow-hidden dark:border-white/10">
        <table class="w-full text-xs">
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                <tr>
                    <td
                        class="w-44 bg-gray-50 px-3 py-2 font-semibold text-gray-700 dark:bg-white/5 dark:text-gray-200">
                        {{ __('CSV format') }}</td>
                    <td class="px-3 py-2">{{ __('UTF-8 CSV with a header row. Column order does not matter.') }}</td>
                </tr>
                <tr>
                    <td class="bg-gray-50 px-3 py-2 font-semibold text-gray-700 dark:bg-white/5 dark:text-gray-200">
                        {{ __('Member identifier') }}</td>
                    <td class="px-3 py-2">
                        {{ __('One of member_email, member_number, national_id, or member_name (or name).') }}</td>
                </tr>
                <tr>
                    <td class="bg-gray-50 px-3 py-2 font-semibold text-gray-700 dark:bg-white/5 dark:text-gray-200">
                        {{ __('loan_status') }}</td>
                    <td class="px-3 py-2">{{ __('pending, approved, active (default), completed, or early_settled.') }}
                    </td>
                </tr>
                <tr>
                    <td class="bg-gray-50 px-3 py-2 font-semibold text-gray-700 dark:bg-white/5 dark:text-gray-200">
                        {{ __('Amount columns') }}</td>
                    <td class="px-3 py-2">
                        {{ __('Pending: amount_requested or amount_approved. Approved/active: amount_approved required. Active: member_portion + master_portion = amount_approved (both may be omitted).') }}
                    </td>
                </tr>
                <tr>
                    <td class="bg-gray-50 px-3 py-2 font-semibold text-gray-700 dark:bg-white/5 dark:text-gray-200">
                        {{ __('Repayment columns') }}</td>
                    <td class="px-3 py-2">
                        {{ __('paid_installments_count and total_amount_repaid for partially or fully repaid active loans. Completed rows mark all installments paid.') }}
                    </td>
                </tr>
                <tr>
                    <td class="bg-gray-50 px-3 py-2 font-semibold text-gray-700 dark:bg-white/5 dark:text-gray-200">
                        {{ __('Tier columns') }}</td>
                    <td class="px-3 py-2">
                        {{ __('loan_tier_number and fund_tier_number optional when tiers can be inferred; is_emergency=1 uses the emergency fund tier.') }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>