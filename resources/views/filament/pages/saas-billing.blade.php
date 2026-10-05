<x-filament-panels::page>
    <div class="space-y-6">
        <section class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h3 class="text-sm font-semibold">{{ __('Active plans') }}</h3>
            <ul class="mt-3 space-y-2 text-sm">
                @foreach ($plans as $plan)
                    <li class="flex justify-between gap-3">
                        <span>{{ $plan->name }} <span class="text-gray-500">({{ $plan->slug }})</span></span>
                        <span>{{ number_format((float) $plan->price, 2) }} {{ $plan->currency }}</span>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h3 class="text-sm font-semibold">{{ __('Recent invoices') }}</h3>
            <ul class="mt-3 divide-y divide-gray-100 dark:divide-white/10 text-sm">
                @forelse ($invoices as $invoice)
                    <li class="flex justify-between gap-3 py-2">
                        <span>{{ $invoice->invoice_number }} · {{ $invoice->status }}</span>
                        <span>{{ number_format((float) $invoice->amount, 2) }} {{ $invoice->currency }}</span>
                    </li>
                @empty
                    <li class="py-2 text-gray-500">{{ __('No invoices yet.') }}</li>
                @endforelse
            </ul>
        </section>
    </div>
</x-filament-panels::page>