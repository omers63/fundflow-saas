<div class="space-y-4 text-sm">
    <ul class="grid grid-cols-2 gap-2 text-xs sm:grid-cols-3 lg:grid-cols-5">
        <li>{{ __('Contributions') }}:
            {{ $classificationStats['contributions'] ?? $classificationStats['contribution'] ?? 0 }}
        </li>
        <li>{{ __('Future contributions') }}: {{ $classificationStats['future_contributions'] ?? 0 }}</li>
        <li>{{ __('Loan repayments') }}:
            {{ $classificationStats['loan_repayments'] ?? $classificationStats['loan_repayment'] ?? 0 }}
        </li>
        <li>{{ __('Reclassified as contribution') }}: {{ $classificationStats['reclassified_as_contribution'] ?? 0 }}
        </li>
        <li>{{ __('Failed rows') }}: {{ $classificationStats['failed'] ?? 0 }}</li>
    </ul>

    @if ($classifiedPaymentsReady)
        <p>
            <x-filament::button tag="a" href="{{ route('tenant.admin.legacy-migration.classified-payments-download') }}"
                color="gray" size="sm" icon="heroicon-o-arrow-down-tray" target="_blank" rel="noopener">
                {{ __('Download classified payments CSV') }}
            </x-filament::button>
        </p>
        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ __('Classification uses the uploaded working CSV files. Re-run Classify after replacing any upload.') }}
        </p>
    @endif

    @if ($classificationErrors !== [])
        <div class="ff-maintenance-callout">
            <p class="font-medium text-amber-800 dark:text-amber-200">
                {{ __('Row errors (first :count)', ['count' => count($classificationErrors)]) }}
            </p>
            <ul class="mt-2 list-disc space-y-1 ps-5 text-xs text-amber-700 dark:text-amber-300">
                @foreach ($classificationErrors as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</div>