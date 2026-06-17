<div class="space-y-4 text-sm text-gray-600 dark:text-gray-300">
    <p>{{ __('Upload a UTF-8 CSV with a header row. Existing members (same email or member number) are skipped.') }}</p>

    <p>
        <a href="{{ route('tenant.downloads.member-import-sample') }}"
            class="font-medium text-primary-600 hover:underline dark:text-primary-400" target="_blank" rel="noopener">
            {{ __('Download sample CSV') }}
        </a>
    </p>

    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
        <table class="min-w-full divide-y divide-gray-200 text-xs dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-3 py-2 text-start font-semibold">{{ __('Column') }}</th>
                    <th class="px-3 py-2 text-start font-semibold">{{ __('Required') }}</th>
                    <th class="px-3 py-2 text-start font-semibold">{{ __('Notes') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ([
                        'name' => [true, __('Full name')],
                        'email' => [true, __('Unique contact email')],
                        'member_number' => [false, __('Optional fixed number; auto-generated when empty')],
                        'phone' => [false, __('Contact phone')],
                        'monthly_contribution_amount' => [false, __('500–3000 in steps of 500 (default 500)')],
                        'joined_at' => [false, __('YYYY-MM-DD (default today)')],
                        'status' => [false, __('active, delinquent, suspended, withdrawn, terminated')],
                        'password' => [false, __('Portal password (≥8 chars; otherwise uses default from modal)')],
                        'parent_member_number' => [false, __('Household parent member number (parent row may appear anywhere in the file)')],
                        'parent_member_email' => [false, __('Household parent email (alternative to number; parent row may appear anywhere in the file)')],
                        'portal_pin' => [false, __('Optional household profile PIN')],
                        'contribution_arrears_cutoff_date' => [false, __('Migration cut-off; overrides modal default per row')],
                        'cutoff_cash_balance' => [false, __('Opening cash credited on import when cut-off date is set')],
                        'cutoff_fund_balance' => [false, __('Opening fund credited on import when cut-off date is set')],
                    ] as $col => [$required, $hint])
                            <tr>
                                <td class="px-3 py-2 font-mono text-gray-900 dark:text-gray-100">{{ $col }}</td>
                                <td class="px-3 py-2">{{ $required ? __('Yes') : __('No') }}</td>
                                <td class="px-3 py-2">{{ $hint }}</td>
                            </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
