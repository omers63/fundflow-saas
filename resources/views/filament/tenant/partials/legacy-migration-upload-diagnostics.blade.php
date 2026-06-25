@if (is_array($uploadDiagnostics ?? null))
    <div class="space-y-3 text-sm">
        @foreach ([
            'members' => __('Members file'),
            'loans' => __('Loans file'),
            'payments' => __('Payments file'),
        ] as $key => $label)
            @php($file = $uploadDiagnostics[$key] ?? null)
            <div class="ff-maintenance-callout">
                <p class="font-medium text-gray-800 dark:text-gray-200">{{ $label }}</p>
                @if ($file)
                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">
                        {{ __('Path') }}: <span class="font-mono">{{ $file['path'] }}</span>
                    </p>
                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">
                        {{ __('Rows') }}: {{ $file['row_count'] }}
                        · {{ __('Updated') }}: {{ \Carbon\Carbon::parse($file['modified_at'])->format('Y-m-d H:i:s') }}
                    </p>
                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">
                        {{ __('Columns') }}: {{ implode(', ', $file['headers']) }}
                    </p>
                    @if ($key === 'loans')
                        <p class="mt-1 text-xs {{ ($file['has_loan_id'] ?? false) ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300' }}">
                            {{ ($file['has_loan_id'] ?? false)
                                ? __('loan_id column detected.')
                                : __('loan_id column not found — add Loan Id / loan_id before classifying.') }}
                        </p>
                    @endif
                @else
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('No file uploaded yet.') }}</p>
                @endif
            </div>
        @endforeach
    </div>
@endif
