@php
$d = $this->getData();
$context = $this->resolvedContext();
@endphp

@component('filament.tenant.partials.ops-overview.shell', [
    'title' => __('Overview'),
    'badge' => null,
    'wrapperClass' => 'ff-loan-insights',
])
    @if (filled($d))
        @include('filament.tenant.widgets.loans.' . $context, ['d' => $d, 'compact' => true])
    @endif
@endcomponent
