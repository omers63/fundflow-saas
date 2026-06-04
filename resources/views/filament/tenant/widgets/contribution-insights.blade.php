@php
    $d = $this->getData();
    $context = $this->resolvedContext();
@endphp

<div class="ff-app-insights w-full max-w-none space-y-3 mb-1">
    @if (filled($d))
        @include('filament.tenant.widgets.contributions.' . $context, ['d' => $d])
    @endif
</div>