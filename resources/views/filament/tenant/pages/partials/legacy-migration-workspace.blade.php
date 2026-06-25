    <div class="ff-legacy-migration mx-auto w-full max-w-5xl space-y-6">
        @include('filament.tenant.partials.legacy-migration-wizard-stepper')

        @if ($currentStep === 1)
            <section class="ff-maintenance-panel">
                <header class="ff-maintenance-panel__header ff-maintenance-panel__header--blue">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('How legacy migration works') }}</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            {{ __('Import members and loans from CSV. Choose whether to replay payment history or snapshot opening balances at a cut-off date.') }}
                        </p>
                    </div>
                </header>
                <div class="ff-maintenance-panel__body space-y-4 text-sm text-gray-600 dark:text-gray-300">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="ff-maintenance-callout">
                            <p class="font-medium text-gray-900 dark:text-white">{{ __('Snapshot (recommended)') }}</p>
                            <p class="mt-1">{{ __('Use cutoff_cash_balance and cutoff_fund_balance per member. Skip mixed payment lists.') }}</p>
                        </div>
                        <div class="ff-maintenance-callout">
                            <p class="font-medium text-gray-900 dark:text-white">{{ __('Historical') }}</p>
                            <p class="mt-1">{{ __('Upload a payments CSV, classify rows, then import contribution and loan repayment history.') }}</p>
                        </div>
                    </div>
                    {{ $this->form }}
                </div>
            </section>
        @endif

        @if ($currentStep === 2)
            <section class="ff-maintenance-panel">
                <header class="ff-maintenance-panel__header ff-maintenance-panel__header--muted">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Upload data') }}</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            {{ __('Set the cut-off date, date format, default password, and upload your CSV files.') }}
                        </p>
                    </div>
                </header>
                <div class="ff-maintenance-panel__body space-y-4">
                    {{ $this->form }}

                    <details class="ff-legacy-wizard-format-docs rounded-lg border border-gray-200 dark:border-white/10">
                        <summary class="cursor-pointer px-4 py-3 text-sm font-medium text-gray-800 dark:text-gray-200">
                            {{ __('CSV column reference & sample downloads') }}
                        </summary>
                        <div class="space-y-6 border-t border-gray-100 px-4 py-4 dark:border-white/10">
                            <div class="space-y-2 text-sm">
                                <p>
                                    <a href="{{ route('tenant.downloads.legacy-members-import-sample') }}" target="_blank" rel="noopener"
                                        class="font-medium text-sky-600 hover:text-sky-700 hover:underline dark:text-sky-400">
                                        {{ __('Download members sample CSV') }}
                                    </a>
                                </p>
                                @include('filament.tenant.partials.legacy-migration-column-table', [
                                    'columns' => \App\Support\LegacyMigrationSampleCsv::memberColumnDocs(),
                                ])
                            </div>
                            <div class="space-y-2 text-sm">
                                <p>
                                    <a href="{{ route('tenant.downloads.legacy-loans-import-sample') }}" target="_blank" rel="noopener"
                                        class="font-medium text-sky-600 hover:text-sky-700 hover:underline dark:text-sky-400">
                                        {{ __('Download loans sample CSV') }}
                                    </a>
                                </p>
                                @include('filament.tenant.partials.legacy-migration-column-table', [
                                    'columns' => \App\Support\LegacyMigrationSampleCsv::loanColumnDocs(),
                                ])
                            </div>
                            @if ($this->isHistoricalStrategy())
                                <div class="space-y-2 text-sm">
                                    <p>
                                        <a href="{{ route('tenant.downloads.legacy-payments-import-sample') }}" target="_blank" rel="noopener"
                                            class="font-medium text-sky-600 hover:text-sky-700 hover:underline dark:text-sky-400">
                                            {{ __('Download payments sample CSV') }}
                                        </a>
                                    </p>
                                    @include('filament.tenant.partials.legacy-migration-column-table', [
                                        'columns' => \App\Support\LegacyMigrationSampleCsv::paymentColumnDocs(),
                                    ])
                                </div>
                            @endif
                        </div>
                    </details>

                    @if ($uploadDiagnostics)
                        <div class="space-y-2">
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ __('Uploaded file summary') }}</p>
                            @include('filament.tenant.partials.legacy-migration-upload-diagnostics', [
                                'uploadDiagnostics' => $uploadDiagnostics,
                            ])
                        </div>
                    @endif
                </div>
            </section>
        @endif

        @if ($currentStep === 3)
            <div class="space-y-6" wire:poll.2s="pollClassificationStatus">
            <section class="ff-maintenance-panel">
                <header class="ff-maintenance-panel__header ff-maintenance-panel__header--muted">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Review & classify') }}</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            @if ($this->isHistoricalStrategy())
                                {{ __('Preview row counts, classify payments, then download and review the classified CSV before importing.') }}
                            @else
                                {{ __('Preview row counts to confirm members and loans before you import.') }}
                            @endif
                        </p>
                    </div>
                </header>
                <div class="ff-maintenance-panel__body space-y-4">
                    @if ($classificationRunning)
                        <div class="flex items-center gap-3 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100">
                            <x-filament::loading-indicator class="h-5 w-5" />
                            <p>{{ __('Classifying payment rows in the background. This page will update when finished.') }}</p>
                        </div>
                    @endif

                    @include('filament.tenant.partials.audit-system.workspace-actions', [
                        'names' => $this->isHistoricalStrategy()
                            ? ['previewMigration', 'classifyPayments']
                            : ['previewMigration'],
                        'class' => '',
                    ])

                    @if ($uploadDiagnostics)
                        @include('filament.tenant.partials.legacy-migration-upload-diagnostics', [
                            'uploadDiagnostics' => $uploadDiagnostics,
                        ])
                    @endif

                    @if ($lastPreview)
                        <div class="space-y-4 text-sm">
                            @foreach (['members' => __('Members'), 'loans' => __('Loans'), 'payments' => __('Payments')] as $key => $label)
                                @php($section = $lastPreview[$key] ?? null)
                                @if ($section)
                                    <div class="ff-maintenance-callout">
                                        <p class="font-medium text-gray-800 dark:text-gray-200">{{ $label }}</p>
                                        <p class="mt-1">{{ __('Rows') }}: {{ $section['row_count'] ?? 0 }}</p>
                                        @if (! empty($section['missing_columns']))
                                            <p class="mt-1 text-red-600 dark:text-red-400">
                                                {{ __('Missing') }}: {{ implode(', ', $section['missing_columns']) }}
                                            </p>
                                        @endif
                                        @foreach ($section['warnings'] ?? [] as $warning)
                                            <p class="mt-1 text-amber-600 dark:text-amber-400">{{ $warning }}</p>
                                        @endforeach
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>

            @if ($classificationStats)
                <section class="ff-maintenance-panel">
                    <header class="ff-maintenance-panel__header ff-maintenance-panel__header--muted">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Payment classification results') }}</h2>
                    </header>
                    <div class="ff-maintenance-panel__body">
                        <div class="ff-ltr-data" dir="ltr">
                            @include('filament.tenant.partials.legacy-migration-classification-results')
                        </div>
                    </div>
                </section>
            @endif
            </div>
        @endif

        @if ($currentStep === 4)
            <section class="ff-maintenance-panel">
                <header class="ff-maintenance-panel__header ff-maintenance-panel__header--emerald">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Import into the fund') }}</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            {{ __('Run a dry run first to validate rows, then import members, loans, and optional payments.') }}
                        </p>
                    </div>
                </header>
                <div class="ff-maintenance-panel__body space-y-4">
                    @include('filament.tenant.partials.audit-system.workspace-actions', [
                        'names' => ['dryRun', 'runMigration'],
                        'class' => '',
                    ])

                    @if ($this->isHistoricalStrategy() && ! $classifiedPaymentsReady)
                        <div class="ff-maintenance-callout">
                            <p class="text-sm text-amber-800 dark:text-amber-200">
                                {{ __('Historical migration: classify payments on the Review step before running the import.') }}
                            </p>
                        </div>
                    @endif
                </div>
            </section>

            @if ($migrationRunning)
                <section class="ff-maintenance-panel" wire:poll.5s="pollMigrationStatus">
                    <div class="ff-maintenance-panel__body flex items-center gap-3 text-sm text-gray-600 dark:text-gray-300">
                        <x-filament::loading-indicator class="h-5 w-5" />
                        <p>{{ __('Migration is running in the background. Results will appear below when finished.') }}</p>
                    </div>
                </section>
            @endif

            @if ($lastRun)
                <section class="ff-maintenance-panel">
                    <header class="ff-maintenance-panel__header ff-maintenance-panel__header--muted">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Last run result') }}</h2>
                    </header>
                    <div class="ff-maintenance-panel__body text-sm">
                        <pre class="ff-ltr-data overflow-x-auto rounded-lg bg-gray-50 p-4 font-mono text-start text-xs dark:bg-gray-900/50" dir="ltr">{{ json_encode($lastRun, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                    </div>
                </section>
            @endif
        @endif

        @if (! in_array($currentStep, [1, 2], true))
            <div class="hidden" aria-hidden="true">
                {{ $this->form }}
            </div>
        @endif

        <footer class="ff-legacy-wizard-footer flex items-center justify-between gap-3 border-t border-gray-100 pt-4 dark:border-white/10">
            @if ($currentStep > 1)
                <x-filament::button type="button" wire:click="previousStep" color="gray" outlined>
                    {{ __('Back') }}
                </x-filament::button>
            @else
                <span></span>
            @endif

            @if ($currentStep < 4)
                <x-filament::button type="button" wire:click="nextStep" color="primary">
                    {{ $currentStep === 3 ? __('Continue to import') : __('Continue') }}
                </x-filament::button>
            @else
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Use Dry run to validate, then Run migration when ready.') }}
                </p>
            @endif
        </footer>
    </div>
