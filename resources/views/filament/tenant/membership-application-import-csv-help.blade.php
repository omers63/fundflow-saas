{{-- Sample download callout --}}
@if (($section ?? null) === 'sample')
    <div class="text-xs leading-relaxed text-gray-600 dark:text-gray-400">
        <p>
            {{ __('Download a ready sample with 20 varied rows (including optional fields):') }}
            <a
                href="{{ route('tenant.downloads.membership-application-import-sample') }}"
                class="font-semibold text-primary-600 underline hover:text-primary-500 dark:text-primary-400"
            >
                membership-applications-sample-20.csv
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
            {{ __('Comma, semicolon (common Excel exports), or tab-separated rows are detected automatically from the header.') }}
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
@endif

{{-- Optional columns table --}}
@if (($section ?? null) === 'optional')
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
                    'transfer_amount' => __('Subscription fee transfer amount (optional, default 0)'),
                    'transfer_date' => __('Subscription fee transfer date (optional, default today; YYYY-MM-DD or DD/MM/YYYY)'),
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
                    <td class="w-56 px-3 py-2 font-semibold text-gray-700 dark:text-gray-200">{{ __('Password fallback') }}</td>
                    <td class="px-3 py-2 text-gray-600 dark:text-gray-400">{!! __('Empty or short <code class="font-mono text-[11px]">password</code> uses the default password set in this modal.') !!}</td>
                </tr>
                <tr>
                    <td class="px-3 py-2 font-semibold text-gray-700 dark:text-gray-200">{{ __('Cut-off date') }}</td>
                    <td class="px-3 py-2 text-gray-600 dark:text-gray-400">{{ __('Set in this modal for the whole import. Cycles before that date are not arrears when you approve. Cash and fund cut-off columns post opening balances on approval.') }}</td>
                </tr>
                <tr>
                    <td class="px-3 py-2 font-semibold text-gray-700 dark:text-gray-200">{{ __('Each row') }}</td>
                    <td class="px-3 py-2 text-gray-600 dark:text-gray-400">{!! __('Creates one <strong class="text-gray-800 dark:text-gray-200">pending membership application</strong> with the credentials and profile fields from that row.') !!}</td>
                </tr>
            </tbody>
        </table>
    </div>
@endif
