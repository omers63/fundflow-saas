<div class="ff-legacy-migration mx-auto w-full max-w-5xl space-y-6">
    @include('filament.tenant.partials.legacy-migration-wizard-stepper')

    {{-- Step 1: Members --}}
    @if ($currentStep === 1)
            <section
                class="ff-maintenance-panel"
                @if ($membersImportRunning) wire:poll.2s="pollWizardStepStatus" @endif
            >
                <header class="ff-maintenance-panel__header ff-maintenance-panel__header--blue">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Step 1: Import members') }}</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            {{ __('Upload the members CSV, set the cut-off and default password, then import member accounts with their opening balances.') }}
                        </p>
                    </div>
                </header>
                <div class="ff-maintenance-panel__body space-y-4">
                    @if ($membersImportRunning)
                        @include('filament.tenant.partials.legacy-migration-running-banner', [
                'message' => __('Importing members in the background. This page will update when finished.'),
            ])
                    @endif

                    {{ $this->form }}

                    @include('filament.tenant.partials.legacy-migration-csv-upload', [
            'wireModel' => 'pendingMembersCsv',
            'label' => __('Members CSV'),
            'description' => __('One row per member. Include the opening balance columns as of the cut-off date.'),
            'summary' => $uploadDiagnostics['members'] ?? null,
            'icon' => 'heroicon-o-users',
        ])

                    @include('filament.tenant.partials.audit-system.workspace-actions', [
                        'names' => ['importMembers'],
                        'class' => '',
                    ])
                </div>
            </section>
    @endif

    {{-- Step 2: Loans (needs both loans + payments CSV) --}}
    @if ($currentStep === 2)
        <section
            class="ff-maintenance-panel"
            @if ($loansImportRunning) wire:poll.2s="pollWizardStepStatus" @endif
        >
            <header class="ff-maintenance-panel__header ff-maintenance-panel__header--muted">
                <div class="min-w-0">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Step 2: Import loans') }}</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Upload the loans CSV and the payments CSV, then import loan records. Repayment windows open on each disbursement date.') }}
                    </p>
                </div>
            </header>
            <div class="ff-maintenance-panel__body space-y-4">
                @if ($loansImportRunning)
                    @include('filament.tenant.partials.legacy-migration-running-banner', [
                        'message' => __('Importing loans in the background. This page will update when finished.'),
                    ])
                @endif

                {{ $this->form }}

                <div class="grid gap-3 sm:grid-cols-2">
                    @include('filament.tenant.partials.legacy-migration-csv-upload', [
                        'wireModel' => 'pendingLoansCsv',
                        'label' => __('Loans CSV'),
                        'description' => __('One row per loan, with disbursement date and amounts.'),
                        'summary' => $uploadDiagnostics['loans'] ?? null,
                        'sampleUrl' => route('tenant.downloads.legacy-loans-import-sample'),
                        'sampleLabel' => __('Download loans sample CSV'),
                        'icon' => 'heroicon-o-banknotes',
                    ])

                    @include('filament.tenant.partials.legacy-migration-csv-upload', [
                        'wireModel' => 'pendingPaymentsCsv',
                        'label' => __('Payments CSV'),
                        'description' => __('Needed here too: on each disbursement date the loan tops up the member fund from their contribution rows in this file. You will reuse the same file to classify payments in step 3.'),
                        'summary' => $uploadDiagnostics['payments'] ?? null,
                        'icon' => 'heroicon-o-credit-card',
                    ])
                </div>

                @if (($uploadDiagnostics['loans']['has_loan_id'] ?? true) === false)
                    <div class="ff-maintenance-callout">
                        <p class="text-sm text-amber-800 dark:text-amber-200">
                            {{ __('The loans CSV has no loan_id column. Add a loan_id (or Loan Id) header so legacy loan numbers are preserved during classification and import.') }}
                        </p>
                    </div>
                @endif

                @include('filament.tenant.partials.audit-system.workspace-actions', [
                    'names' => ['importLoans'],
                    'class' => '',
                ])

                <details class="ff-legacy-wizard-format-docs rounded-lg border border-gray-200 dark:border-white/10">
                    <summary class="cursor-pointer px-4 py-3 text-sm font-medium text-gray-800 dark:text-gray-200">
                        {{ __('Loans CSV column reference') }}
                    </summary>
                    <div class="space-y-4 border-t border-gray-100 px-4 py-4 dark:border-white/10">
                        @include('filament.tenant.partials.legacy-migration-column-table', [
                            'columns' => \App\Support\LegacyMigrationSampleCsv::loanColumnDocs(),
                        ])
                    </div>
                </details>
            </div>
        </section>
    @endif

    {{-- Step 3: Classify payments (reuses payments CSV from step 2) --}}
    @if ($currentStep === 3)
        <section
            class="ff-maintenance-panel"
            @if ($classificationRunning) wire:poll.2s="pollWizardStepStatus" @endif
        >
            <header class="ff-maintenance-panel__header ff-maintenance-panel__header--muted">
                <div class="min-w-0">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Step 3: Classify payments') }}</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Each payment starts as a contribution. Rows inside an open loan repayment window (after disbursement, while a fund portion remains) become loan repayments.') }}
                    </p>
                </div>
            </header>
            <div class="ff-maintenance-panel__body space-y-4">
                @if ($classificationRunning)
                    @include('filament.tenant.partials.legacy-migration-running-banner', [
                        'message' => __('Classifying payment rows in the background. Continue to step 4 when finished.'),
                    ])
                @endif

                @include('filament.tenant.partials.legacy-migration-csv-upload', [
                    'wireModel' => 'pendingPaymentsCsv',
                    'label' => __('Payments CSV'),
                    'description' => __('This is the same file you uploaded in step 2. Replace it only if the payment data changed.'),
                    'summary' => $uploadDiagnostics['payments'] ?? null,
                    'icon' => 'heroicon-o-credit-card',
                ])

                @include('filament.tenant.partials.audit-system.workspace-actions', [
                    'names' => ['classifyPayments'],
                    'class' => '',
                ])
            </div>
        </section>
    @endif

    {{-- Step 4: Classification file --}}
    @if ($currentStep === 4)
        <section
            class="ff-maintenance-panel"
            @if ($classificationRunning) wire:poll.2s="pollWizardStepStatus" @endif
        >
            <header class="ff-maintenance-panel__header ff-maintenance-panel__header--muted">
                <div class="min-w-0">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Step 4: Classification file') }}</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Review the classification counts and download the CSV before applying the migration.') }}
                    </p>
                </div>
            </header>
            <div class="ff-maintenance-panel__body space-y-4">
                @if (!$classifiedPaymentsReady)
                    <div class="ff-maintenance-callout">
                        <p class="text-sm text-amber-800 dark:text-amber-200">
                            {{ __('Complete step 3 to produce the classification file.') }}
                        </p>
                    </div>
                @else
                    <a href="{{ route('tenant.admin.legacy-migration.classified-payments-download') }}"
                        class="inline-flex items-center gap-2 rounded-lg border border-sky-200 bg-sky-50 px-4 py-2 text-sm font-medium text-sky-700 transition hover:bg-sky-100 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300">
                        <x-filament::icon icon="heroicon-o-arrow-down-tray" class="h-4 w-4" />
                        {{ __('Download classified payments CSV') }}
                    </a>
                @endif

                @if ($classificationStats)
                    <div class="ff-ltr-data" dir="ltr">
                        @include('filament.tenant.partials.legacy-migration-classification-results')
                    </div>
                @endif
            </div>
        </section>
    @endif

    {{-- Step 5: Apply migration --}}
    @if ($currentStep === 5)
        <section class="ff-maintenance-panel">
            <header class="ff-maintenance-panel__header ff-maintenance-panel__header--emerald">
                <div class="min-w-0">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Step 5: Apply migration') }}</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Replay the classified CSV to post contributions and loan repayments. Members and loans must already be imported.') }}
                    </p>
                </div>
            </header>
            <div class="ff-maintenance-panel__body space-y-4">
                @if (!$classifiedPaymentsReady)
                    <div class="ff-maintenance-callout">
                        <p class="text-sm text-amber-800 dark:text-amber-200">
                            {{ __('Produce and review the classification file on step 4 before applying.') }}
                        </p>
                    </div>
                @endif

                @include('filament.tenant.partials.audit-system.workspace-actions', [
                    'names' => ['dryRun', 'runMigration'],
                    'class' => '',
                ])
            </div>
        </section>

        @if ($migrationRunning)
            <section class="ff-maintenance-panel" wire:poll.5s="pollMigrationStatus">
                <div class="ff-maintenance-panel__body">
                    @include('filament.tenant.partials.legacy-migration-running-banner', [
                        'message' => __('Migration is running in the background. Results will appear below when finished.'),
                    ])
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

    {{-- Keep the settings form state mounted on steps that hide it. --}}
    @if (!in_array($currentStep, [1, 2], true))
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

        @if ($currentStep < 5)
            <x-filament::button type="button" wire:click="nextStep" color="primary">
                {{ __('Continue') }}
            </x-filament::button>
        @else
            <p class="text-xs text-gray-500 dark:text-gray-400">
                {{ __('Use Dry run to validate, then Apply migration when ready.') }}
            </p>
        @endif
    </footer>
</div>
