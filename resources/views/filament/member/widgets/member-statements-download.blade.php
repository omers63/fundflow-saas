<x-member::panel :title="__('Download center')">
    <p class="mb-3 text-sm text-gray-600">
        {{ __('Export your activity or download monthly statements and loan schedules.') }}
    </p>
    <x-member::panel-actions>
        <a href="{{ $activityPageUrl }}" wire:navigate class="fi-btn fi-btn-size-sm fi-outlined fi-color-gray">
            {{ __('Activity & CSV export') }}
        </a>
        @if (filled($latestStatementPdfUrl))
            <a href="{{ $latestStatementPdfUrl }}" class="fi-btn fi-btn-size-sm fi-outlined fi-color-primary"
                target="_blank" rel="noopener">
                {{ __('Latest statement') }} ({{ $latestStatementPeriod }})
            </a>
        @endif
        @if (filled($loanSchedulePdfUrl))
            <a href="{{ $loanSchedulePdfUrl }}" class="fi-btn fi-btn-size-sm fi-outlined fi-color-primary" target="_blank"
                rel="noopener">
                {{ __('Active loan schedule PDF') }}
            </a>
        @else
            <a href="{{ $loansUrl }}" wire:navigate class="fi-btn fi-btn-size-sm fi-outlined fi-color-gray">
                {{ __('Loan schedules') }}
            </a>
        @endif
    </x-member::panel-actions>
</x-member::panel>