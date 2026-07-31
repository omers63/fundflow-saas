{{-- Sample download callout --}}
@if (($section ?? null) === 'sample')
    <div class="text-xs leading-relaxed text-gray-600 dark:text-gray-400">
        <p>
            <a
                href="{{ route('tenant.downloads.member-import-sample') }}"
                class="font-semibold text-primary-600 underline hover:text-primary-500 dark:text-primary-400"
                target="_blank"
                rel="noopener"
            >
                {{ __('Download sample CSV') }}
            </a>
        </p>
    </div>
@endif

{{-- Format tip --}}
@if (($section ?? null) === 'format')
    <div class="text-xs leading-relaxed text-gray-600 dark:text-gray-400">
        <p>
            {!! __('Use a UTF-8 CSV with a <strong class="text-gray-950 dark:text-white">header row</strong>.') !!}
            {{ __('Column names must match exactly; order can be anything.') }}
            {{ __('Existing members (same email or member number) are skipped.') }}
        </p>
    </div>
@endif

{{-- Required columns table --}}
@if (($section ?? null) === 'required')
    <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-white/10">
        <table class="w-full text-xs">
            <thead class="bg-gray-50/60 dark:bg-white/5">
                <tr>
                    <th class="w-56 px-3 py-2 text-start font-semibold text-gray-700 dark:text-gray-200">{{ __('Column') }}</th>
                    <th class="px-3 py-2 text-start font-semibold text-gray-700 dark:text-gray-200">{{ __('Description') }}</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ([
                                'name' => __('Full name'),
                                'email' => __('Unique contact email'),
                            ] as $col => $hint)
                            <tr>
                                <td class="px-3 py-2 align-top">
                                    <code class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[11px] text-gray-800 dark:bg-white/10 dark:text-gray-200">{{ $col }}</code>
                                    </td>
                                    <td class="px-3 py-2 text-gray-600 dark:text-gray-400">{{ $hint }}</td>
                                    </tr>
                        @endforeach
                                </tbody>
                            </table>
                        </div>
@endif
       
         {{-- Opt                ional columns table --}}
@if (($section ?? null) === 'optional')
    <div class="                overflow-hidden rounded-lg border border-gray-200 dark:border-white/10">
        <table class                ="w-full text-xs">

                                                                <thead class="bg-gray-50/60 dark:bg-white/5">

                                                                    <tr>
                                    <th class="w-56 px-3 py-2 text-start font-semibold text-gray-700 dark:text-gray-200">{{ __('Column') }}</th>
                                    <th class="px-3 py-2 text-start font-semibold text-gray-700 dark:text-gray-200">{{ __('Description') }}</th>
                                </tr>
            </thead>            
            <tbody class            ="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ([
                                    'member_number' => __('Optional fixed number; auto-generated when empty'),
                                    'phone' => __('Contact phone'),
                                    'monthly_contribution_amount' => __('500–3000 in steps of 500 (default 500)'),
                                    'joined_at' => __('YYYY-MM-DD (default today)'),
                                    'status' => __('active, inactive, withdrawn — legacy labels mapped on import'),
                                    'password' => __('Portal password (≥8 chars; otherwise uses default from modal)'),
                                    'parent_member_number' => __('Household parent member number (parent row may appear anywhere in the file)'),
                                    'parent_member_email' => __('Household parent email (alternative to number; parent row may appear anywhere in the file)'),
                                    'portal_pin' => __('Optional household profile PIN'),
                                    'contribution_arrears_cutoff_date' => __('Migration cut-off; overrides modal default per row'),
                                    'cutoff_cash_balance' => __('Opening cash credited on import when cut-off date is set'),
                                    'cutoff_fund_balance' => __('Opening fund credited on import when cut-off date is set'),
                        'gender' => __('male, female, other — populates member profile'),
                        'marital_status' => __('single, married, divorced, widowed — populates member profile'),
                        'national_id' => __('National ID / Iqama — populates member profile'),
                        'date_of_birth' => __('YYYY-MM-DD or d/m/Y — populates member profile'),
                        'city' => __('City — populates member profile'),
                        'address' => __('Street address — populates member profile'),
                        'mobile_phone' => __('Mobile phone — populates member profile (fills phone when phone is empty)'),
                        'home_phone' => __('Home phone — populates member profile'),
                        'work_phone' => __('Work phone — populates member profile'),
                        'work_place' => __('Work place — populates member profile'),
                        'residency_place' => __('Residency place — populates member profile'),
                        'occupation' => __('Occupation — populates member profile'),
                        'employer' => __('Employer — populates member profile'),
                        'monthly_income' => __('Monthly income amount — populates member profile'),
                        'bank_account_number' => __('Bank account number — populates member profile'),
                        'iban' => __('IBAN — populates member profile'),
                        'next_of_kin_name' => __('Next of kin name — populates member profile'),
                        'next_of_kin_phone' => __('Next of kin phone — populates member profile'),
                        'application_fee_amount' => __('Application fee amount (alias: membership_fee_amount)'),
                        'application_fee_transfer_date' => __('Application fee transfer date (alias: membership_fee_transfer_date)'),
                        'application_fee_transfer_reference' => __('Application fee transfer reference (alias: membership_fee_transfer_reference)'),
                        'applicant_message' => __('Applicant message (alias: message)'),
                    ] as $col => $hint)
                                            <tr>
                                                <td class="px-3 py-2 align-top">
                                                    <code class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[11px] text-gray-800 dark:bg-white/10 dark:text-gray-200">{{ $col }}</code>
                                                </td>

                                                                        <td class="px-3 py-2 text-gray-600 dark:text-gray-400">{{ $hint }}</td>
                                            </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

{{-- Row handling rules --}}
@if (($section ?? null) === 'rules')
    <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-white/10">
        <table class="w-full text-xs">
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                <tr>
                    <td class="w-56 px-3 py-2 font-semibold text-gray-700 dark:text-gray-200">{{ __('Duplicates') }}</td>
                    <td class="px-3 py-2 text-gray-600 dark:text-gray-400">{{ __('Existing members (same email or member number) are skipped.') }}</td>
                </tr>
                <tr>
                    <td class="px-3 py-2 font-semibold text-gray-700 dark:text-gray-200">{{ __('Password fallback') }}</td>
                    <td class="px-3 py-2 text-gray-600 dark:text-gray-400">{!! __('Empty or short <code class="font-mono text-[11px]">password</code> uses the default password set in this modal.') !!}</td>
                </tr>
                <tr>
                    <td class="px-3 py-2 font-semibold text-gray-700 dark:text-gray-200">{{ __('Cut-off date') }}</td>
                    <td class="px-3 py-2 text-gray-600 dark:text-gray-400">{{ __('Default for all rows when the CSV does not specify contribution_arrears_cutoff_date. Required when posting cut-off cash or fund balances.') }}</td>
                </tr>
            </tbody>
        </table>
    </div>
@endif
