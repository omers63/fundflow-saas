@php
    use App\Support\Reconciliation\ReconciliationExceptionPresenter;

    $counts = $this->getOpenExceptionCountByDomain();
@endphp

@if ($counts !== [])
    <div class="ff-recon-domain-strip mb-4 flex flex-wrap gap-2">
        @foreach ($counts as $domain => $count)
            <span
                class="inline-flex items-center gap-2 rounded-full border border-gray-200 bg-gray-50 px-3 py-1 text-xs font-medium text-gray-700 dark:border-white/10 dark:bg-white/5 dark:text-gray-200">
                <span>{{ ReconciliationExceptionPresenter::domainLabel($domain) }}</span>
                <span class="rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-semibold text-white">{{ $count }}</span>
            </span>
        @endforeach
    </div>
@endif