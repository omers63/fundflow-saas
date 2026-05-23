<div class="space-y-4 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
    <div class="rounded-lg border border-blue-200 bg-blue-50/80 p-3 text-xs dark:border-blue-500/30 dark:bg-blue-500/10">
        <p class="font-semibold text-blue-900 dark:text-blue-200 mb-1">{{ __('Need a starter file?') }}</p>
        <p>
            {{ __('Download a ready sample with 20 varied rows (including optional fields):') }}
            <a
                href="{{ route('tenant.downloads.membership-application-import-sample') }}"
                class="font-semibold text-blue-700 underline hover:text-blue-600 dark:text-blue-300 dark:hover:text-blue-200"
            >
                membership-applications-sample-20.csv
            </a>
        </p>
    </div>

    <div class="rounded-lg border border-gray-200 bg-gray-50/80 p-3 text-xs dark:border-white/10 dark:bg-white/5">
        <p>
            {!! __('Use a UTF-8 CSV with a <strong class="text-gray-950 dark:text-white">header row</strong>.') !!}
            {{ __('Column names must match exactly; order can be anything.') }}
            {{ __('Comma, semicolon (common Excel exports), or tab-separated rows are detected automatically from the header.') }}
        </p>
    </div>

    <div class="rounded-lg border border-gray-200 overflow-hidden dark:border-white/10">
        <div class="bg-gray-50 dark:bg-white/5 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-200">
            {{ __('Required Columns') }}
        </div>
        <table class="w-full text-xs">
            <thead class="bg-gray-50/60 dark:bg-white/5">
                <tr>
                    <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200 w-56">{{ __('Column') }}</th>
                    <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">{{ __('Description') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ([
                    'name' => __('Applicant full name'),
                    'email' => __('Login email for the pending application'),
                    'mobile_phone' => __('Mobile number (used for SMS / WhatsApp)'),
                    'iban' => __('IBAN'),
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

    <div class="rounded-lg border border-gray-200 overflow-hidden dark:border-white/10">
        <div class="bg-gray-50 dark:bg-white/5 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-200">
            {{ __('Optional Columns') }}
        </div>
        <table class="w-full text-xs">
            <thead class="bg-gray-50/60 dark:bg-white/5">
                <tr>
                    <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200 w-56">{{ __('Column') }}</th>
                    <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">{{ __('Description') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ([
                    'national_id' => __('National / ID number'),
                    'date_of_birth' => __('Date of birth (YYYY-MM-DD); omit if unknown'),
                    'city' => __('City'),
                    'address' => __('Full address (quote if it contains commas)'),
                    'bank_account_number' => __('Bank account number'),
                    'next_of_kin_name' => __('Next of kin full name'),
                    'next_of_kin_phone' => __('Next of kin phone number'),
                    'password' => __('If 8+ characters, overrides the default password provided in the modal'),
                    'application_type' => __('new, resume, or renew (blank defaults to new)'),
                    'gender' => __('male, female, other'),
                    'marital_status' => __('single, married, divorced, widowed, other'),
                    'membership_date' => __('Membership date (YYYY-MM-DD, DD/MM/YYYY, etc.)'),
                    'home_phone' => __('Home phone'),
                    'work_phone' => __('Work phone'),
                    'work_place' => __('Work place'),
                    'residency_place' => __('Residency place'),
                    'occupation' => __('Occupation'),
                    'employer' => __('Employer'),
                    'monthly_income' => __('Monthly income (numeric, >= 0)'),
                    'cutoff_cash_balance' => __('Cut-off cash balance (optional, default 0; credited on approval)'),
                    'cutoff_fund_balance' => __('Cut-off fund balance (optional, default 0; credited on approval)'),
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

    <div class="rounded-lg border border-gray-200 overflow-hidden dark:border-white/10">
        <div class="bg-gray-50 dark:bg-white/5 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-200">
            {{ __('Row Handling Rules') }}
        </div>
        <table class="w-full text-xs">
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                <tr>
                    <td class="px-3 py-2 font-semibold text-gray-700 dark:text-gray-200 w-56">{{ __('Password fallback') }}</td>
                    <td class="px-3 py-2">{!! __('Empty or short <code class="font-mono text-[11px]">password</code> uses the default password set in this modal.') !!}</td>
                </tr>
                <tr>
                    <td class="px-3 py-2 font-semibold text-gray-700 dark:text-gray-200">{{ __('Cut-off date') }}</td>
                    <td class="px-3 py-2">{{ __('Set in this modal for the whole import. Cycles before that date are not arrears when you approve. Cash and fund cut-off columns post opening balances on approval.') }}</td>
                </tr>
                <tr>
                    <td class="px-3 py-2 font-semibold text-gray-700 dark:text-gray-200">{{ __('Each row') }}</td>
                    <td class="px-3 py-2">{!! __('Creates one <strong class="text-gray-800 dark:text-gray-200">pending membership application</strong> with the credentials and profile fields from that row.') !!}</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
