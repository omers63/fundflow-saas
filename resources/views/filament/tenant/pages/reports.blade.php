<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($this->reportCards() as $card)
            <a href="{{ $card['url'] ?? '#' }}" @class([
                'group relative block overflow-hidden rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition hover:border-sky-200 hover:shadow-md dark:border-white/10 dark:bg-slate-800 dark:hover:border-sky-800/50',
                'pointer-events-none opacity-60' => blank($card['url']),
            ])>
                <div class="flex items-start gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-sky-100 bg-sky-50 text-sky-600 dark:border-sky-900/40 dark:bg-sky-950/40 dark:text-sky-300">
                        <x-dynamic-component :component="$card['icon']" class="h-5 w-5" />
                    </div>
                    <div class="min-w-0">
                        <h3
                            class="text-sm font-semibold text-gray-900 group-hover:text-sky-700 dark:text-white dark:group-hover:text-sky-300">
                            {{ $card['title'] }}
                        </h3>
                        <p class="mt-1 text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                            {{ $card['description'] }}
                        </p>
                    </div>
                </div>
            </a>
        @endforeach
    </div>

    <section
        class="mt-6 rounded-xl border border-gray-200 bg-white px-4 py-5 shadow-sm dark:border-white/10 dark:bg-slate-900/60">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Custom report builder') }}</h3>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            {{ __('Choose a report type, date range, and export format. Generation uses existing export services where available.') }}
        </p>
        <form class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" wire:submit="generateCustomReport">
            <div>
                <label
                    class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Report type') }}</label>
                <select wire:model="reportType"
                    class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900">
                    <option value="collections">{{ __('Collections') }}</option>
                    <option value="loans">{{ __('Loan portfolio') }}</option>
                    <option value="reconciliation">{{ __('Reconciliation summary') }}</option>
                    <option value="audit">{{ __('Audit trail') }}</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('From') }}</label>
                <input type="date" wire:model="reportFrom"
                    class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Until') }}</label>
                <input type="date" wire:model="reportUntil"
                    class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900" />
            </div>
            <div>
                <label
                    class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Format') }}</label>
                <select wire:model="reportFormat"
                    class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-gray-900">
                    <option value="pdf">PDF</option>
                    <option value="csv">CSV</option>
                    <option value="xlsx">Excel</option>
                </select>
            </div>
            <div class="sm:col-span-2 lg:col-span-4 flex justify-end">
                <button type="submit" class="ff-tenant-btn ff-tenant-btn--primary px-4 py-2 text-sm">
                    {{ __('Generate report') }}
                </button>
            </div>
        </form>
    </section>
</x-filament-panels::page>