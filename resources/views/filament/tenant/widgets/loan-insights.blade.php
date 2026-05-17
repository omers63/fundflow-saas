@php
    $d = $this->getData();
    $context = $this->resolvedContext();
@endphp

@if (filled($d))
    <div class="ff-app-insights w-full max-w-none space-y-3 mb-1">
        @include('filament.tenant.widgets.loans.' . $context, ['d' => $d])
    </div>
@endif