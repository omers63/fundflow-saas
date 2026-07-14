@php
use App\Filament\Tenant\Resources\Loans\LoanResource;

$activeView = LoanResource::resolveDelinquencyView();
$overdueCount = LoanResource::overdueInstallmentsCount();
$guarantorCount = LoanResource::guarantorExposureCount();
@endphp

<div class="mb-4 space-y-2">
    <div class="ff-tenant-tab-pills flex flex-wrap gap-2">
        @foreach ([
                'overdue' => __('Overdue installments'),
                'guarantor' => __('Guarantor exposure'),
            ] as $view => $label)
                        @php
                $count = $view === 'overdue' ? $overdueCount : $guarantorCount;
                        @endphp
                        <a href="{{ LoanResource::listDelinquencyViewUrl($view) }}" @class([
                    'ff-tenant-tab-pills__item no-underline',
                    'ff-tenant-tab-pills__item--active' => $activeView === $view,
                    'ff-tenant-tab-pills__item--danger' => $activeView !== $view && $view === 'overdue' && $overdueCount > 0,
                    'ff-tenant-tab-pills__item--warning' => $activeView !== $view && $view === 'guarantor' && $guarantorCount > 0,
                ])>
                            <x-ff-tab-pill-label :label="$label" :key="$view" />
                            @if ($count > 0)
                                <span @class([
                        'ms-1 rounded-full px-1.5 py-0.5 text-[10px] font-bold tabular-nums',
                        'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-200' => $activeView === $view,
                        'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-200' => $activeView !== $view && $view === 'overdue',
                        'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' => $activeView !== $view && $view === 'guarantor',
                    ])>{{ $count }}</span>
                            @endif
                        </a>
        @endforeach
    </div>
</div>
