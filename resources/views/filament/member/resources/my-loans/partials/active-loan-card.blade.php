@php
    $showSchedule = $showSchedule ?? ($loan['show_schedule'] ?? true);
    $canSettle = $canSettle ?? ($loan['show_settle_button'] ?? false);
    $hasActions = $canSettle || filled($loan['schedule_pdf_url'] ?? null);
@endphp

<x-member::panel :title="$loan['label']" :link="$loan['view_url']" :link-label="__('Details')"
    class="ff-member-loan-card">
    <div class="ff-member-loan-card__summary">
        @if (filled($loan['meta'] ?? null))
            <p class="ff-member-dashboard-meta mb-2">{{ $loan['meta'] }}</p>
        @endif

        <div class="ff-member-loan-card__header-row">
            <div class="ff-member-loan-card__header-main">
                <x-member::amount :value="$loan['outstanding']" :currency="$currency" class="text-xl font-bold" />
                <x-member::chip :variant="$loan['status_variant'] ?? 'green'">{{ $loan['status_label'] }}</x-member::chip>
            </div>
            <p class="ff-member-loan-card__meta-line ff-member-dashboard-meta">{{ $loan['installments_label'] }}</p>
        </div>

        <x-member::progress-bar :percent="$loan['repay_percent'] ?? 0" class="mb-2" />
        <p class="ff-member-dashboard-meta mb-3">{{ $loan['repaid_label'] }}</p>

        @if (!empty($loan['next_emi']))
            <div class="ff-member-dashboard-emi-row mb-3">
                <div class="min-w-0">
                    <p class="ff-member-dashboard-meta m-0">{{ __('Next EMI due') }}</p>
                    <p class="m-0 text-sm font-semibold">{{ $loan['next_emi']['due_date'] }}</p>
                </div>
                <x-member::amount :value="$loan['next_emi']['amount']" :currency="$currency"
                    class="text-base font-bold text-primary-700" />
            </div>
        @endif

        @if (filled($loan['guarantor_name'] ?? null))
            <p class="ff-member-dashboard-meta mb-0">
                {{ __('Guarantor') }}: {{ $loan['guarantor_name'] }}
            </p>
        @endif
    </div>

    @if ($hasActions)
        <x-member::panel-actions>
            @if (filled($loan['schedule_pdf_url'] ?? null))
                <a href="{{ $loan['schedule_pdf_url'] }}" class="fi-btn fi-btn-size-sm fi-outlined fi-color-gray"
                    target="_blank" rel="noopener">
                    {{ __('Download schedule PDF') }}
                </a>
            @endif
            @if ($canSettle)
                <button
                    type="button"
                    wire:click.prevent="openEarlySettlement({{ (int) $loan['id'] }})"
                    wire:loading.attr="disabled"
                    wire:target="openEarlySettlement"
                    class="fi-btn fi-btn-size-sm fi-outlined fi-color-primary"
                >
                    {{ __('Early settlement') }}
                </button>
            @endif
        </x-member::panel-actions>
    @endif

    @if ($showSchedule)
        @livewire(\App\Filament\Member\Widgets\MemberLoanInstallmentsTableWidget::class, ['loanId' => $loan['id']], key('loan-installments-' . $loan['id']))
    @endif
</x-member::panel>
