@if (\App\Support\BusinessDay::isOverridden())
    <div
        class="border-b border-amber-300 bg-amber-50 px-4 py-2 text-center text-xs font-medium text-amber-900 dark:border-amber-500/40 dark:bg-amber-950/40 dark:text-amber-100">
        {{ __('Business day override active: app date is :business_day (calendar :calendar_day).', [
            'business_day' => \App\Support\BusinessDay::now()->toFormattedDateString(),
            'calendar_day' => \App\Support\BusinessDay::calendarToday()->toFormattedDateString(),
        ]) }}
        <span class="font-normal text-amber-800 dark:text-amber-200">
            {{ __('Update under Settings → General.') }}
        </span>
    </div>
@endif