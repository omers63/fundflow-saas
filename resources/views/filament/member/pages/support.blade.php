<x-filament-panels::page>

<div class="space-y-6">

    <div class="rounded-xl bg-primary-50 dark:bg-primary-900/20 ring-1 ring-primary-200 dark:ring-primary-700 p-5">
        <div class="flex items-start gap-3">
            <x-heroicon-o-chat-bubble-left-right class="w-6 h-6 text-primary-600 dark:text-primary-400 mt-0.5 flex-shrink-0" />
            <div>
                <p class="text-sm font-semibold text-primary-800 dark:text-primary-300">{{ __('How can we help?') }}</p>
                <p class="text-sm text-primary-700 dark:text-primary-400 mt-1">
                    {!! __('Use the <strong>Submit Request</strong> button above to send a message to the fund administrators. You can inquire about your account, request a cash deposit, ask about your loan, or raise a complaint. Admins will respond via the messaging system.') !!}
                </p>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach([
            ['icon' => 'heroicon-o-banknotes', 'title' => __('Cash Deposit'), 'desc' => __('Request a cash top-up to your account'), 'color' => 'emerald'],
            ['icon' => 'heroicon-o-document-currency-dollar', 'title' => __('Loan Inquiry'), 'desc' => __('Questions about your loan or application'), 'color' => 'blue'],
            ['icon' => 'heroicon-o-calculator', 'title' => __('Contribution Query'), 'desc' => __('Questions about your monthly contributions'), 'color' => 'amber'],
            ['icon' => 'heroicon-o-chart-bar', 'title' => __('Balance / Account'), 'desc' => __('Queries about your balances or ledger'), 'color' => 'purple'],
            ['icon' => 'heroicon-o-exclamation-triangle', 'title' => __('Complaint'), 'desc' => __('Raise a concern or formal complaint'), 'color' => 'red'],
            ['icon' => 'heroicon-o-question-mark-circle', 'title' => __('General Inquiry'), 'desc' => __('Any other question or request'), 'color' => 'gray'],
        ] as $cat)
        <div class="rounded-xl bg-white dark:bg-gray-800 ring-1 ring-gray-200 dark:ring-gray-700 p-4 shadow-sm">
            <div class="flex items-center gap-2 mb-2">
                <x-dynamic-component :component="$cat['icon']" class="w-5 h-5 text-{{ $cat['color'] }}-500" />
                <span class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $cat['title'] }}</span>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $cat['desc'] }}</p>
        </div>
        @endforeach
    </div>

    <div class="rounded-xl bg-gray-50 dark:bg-gray-800 ring-1 ring-gray-200 dark:ring-gray-700 p-4 text-center">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            <x-heroicon-o-clock class="w-4 h-4 inline-block mr-1 text-gray-400" />
            {!! __('Typical response time: <strong>1–2 business days</strong>. For urgent matters, contact your fund administrator directly.') !!}
        </p>
    </div>

</div>

</x-filament-panels::page>
