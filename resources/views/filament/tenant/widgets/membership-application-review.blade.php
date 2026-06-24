@php
    $d = $this->getData();

    if ($d === []) {
        return;
    }
@endphp

<div
    class="ff-application-review-banner mb-1 overflow-hidden rounded-2xl border border-amber-200/80 bg-gradient-to-br from-amber-50 via-white to-sky-50/40 shadow-sm dark:border-amber-800/30 dark:from-amber-950/30 dark:via-gray-900 dark:to-sky-950/20">
    <div class="border-b border-amber-100/80 px-4 py-2.5 dark:border-amber-900/30">
        <p class="text-[10px] font-bold uppercase tracking-[0.12em] text-amber-700 dark:text-amber-300">
            {{ __('Review workflow') }}</p>
        <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-300">
            {{ __('Confirm documents and fee transfer, then approve or reject from the header.') }}</p>
    </div>
    <div class="px-2 py-3 sm:px-4">
        <x-member-lifecycle-stepper :steps="$d['steps']" />
    </div>
    <div class="flex flex-wrap gap-2 border-t border-amber-100/80 px-4 py-2.5 text-[11px] dark:border-amber-900/30">
        <span @class([
            'rounded-full px-2.5 py-1 font-semibold',
            'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' => $d['has_form'],
            'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' => !$d['has_form'],
        ])>{{ $d['has_form'] ? __('Signed form on file') : __('Signed form missing') }}</span>
        <span @class([
            'rounded-full px-2.5 py-1 font-semibold',
            'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' => $d['has_receipt'],
            'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' => !$d['has_receipt'],
        ])>{{ $d['has_receipt'] ? __('Fee evidence on file') : __('Fee evidence missing') }}</span>
    </div>
</div>