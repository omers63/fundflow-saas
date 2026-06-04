@php
    $d = $this->getData();
    $context = $this->resolvedContext();
@endphp

<div class="ff-app-insights w-full max-w-none space-y-3 mb-1">
    @if (filled($d))
        @if ($context === 'loan_detail')
            @include('filament.tenant.widgets.loans.loan_detail', ['d' => $d])
        @else
            @include('filament.member.widgets.loans.member-portfolio', ['d' => $d])
            @if (! ($d['eligibility']['eligible'] ?? $d['eligible'] ?? false) && ($d['eligibility']['has_pending_override_request'] ?? false))
                <div class="rounded-xl border border-amber-200/80 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-amber-950/30 dark:text-amber-200">
                    {{ __('Eligibility review pending with admin.') }}
                </div>
            @endif
        @endif
    @endif
</div>