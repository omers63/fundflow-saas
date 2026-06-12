<div class="space-y-4 text-sm">
    <ul class="grid grid-cols-2 gap-2 text-xs sm:grid-cols-3 lg:grid-cols-5">
        <li>{{ __('Contributions') }}: {{ $classificationStats['contribution'] ?? 0 }}</li>
        <li>{{ __('Loan repayments') }}: {{ $classificationStats['loan_repayment'] ?? 0 }}</li>
        <li>{{ __('Unclassified') }}: {{ $classificationStats['unclassified'] ?? 0 }}</li>
        <li>{{ __('Ignored') }}: {{ $classificationStats['ignore'] ?? 0 }}</li>
        <li>{{ __('Failed rows') }}: {{ $classificationStats['failed'] ?? 0 }}</li>
    </ul>

    @if ($classifiedPaymentsReady)
        <p>
            <x-filament::button wire:click="downloadClassifiedPayments" color="gray" size="sm"
                icon="heroicon-o-arrow-down-tray">
                {{ __('Download classified payments CSV') }}
            </x-filament::button>
        </p>
        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ __('Open in Excel or a spreadsheet editor. Review payment_type, fix unclassified rows, then re-upload as your payments CSV before running the migration.') }}
        </p>
    @endif

    @if ($classificationErrors !== [])
        <div class="ff-maintenance-callout">
            <p class="font-medium text-amber-800 dark:text-amber-200">
                {{ __('Row errors (first :count)', ['count' => count($classificationErrors)]) }}</p>
            <ul class="mt-2 list-disc space-y-1 ps-5 text-xs text-amber-700 dark:text-amber-300">
                @foreach ($classificationErrors as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</div>