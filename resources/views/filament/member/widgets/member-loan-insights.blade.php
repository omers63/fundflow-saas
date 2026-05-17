@php
    $d = $this->getData();
    $context = $this->resolvedContext();
@endphp

@if (filled($d))
    <div class="ff-app-insights w-full max-w-none space-y-3 mb-1">
        @if ($context === 'loan_detail')
            @include('filament.tenant.widgets.loans.loan_detail', ['d' => $d])
        @else
            @include('filament.member.widgets.loans.member-portfolio', ['d' => $d])
        @endif
    </div>
@endif