@php
$d = $this->getData();
$context = $this->resolvedContext();
@endphp

<div class="ff-app-insights ff-loan-insights w-full max-w-none space-y-2 mb-0">
    @if (filled($d))
        @include('filament.tenant.widgets.loans.' . $context, ['d' => $d, 'compact' => true])
    @endif
</div>