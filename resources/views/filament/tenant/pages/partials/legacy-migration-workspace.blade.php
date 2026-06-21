    <div class="ff-legacy-migration ff-audit-wizard mx-auto w-full max-w-5xl space-y-6">
        {{-- Step navigation --}}
        <nav class="ff-audit-wizard-steps ff-tenant-tab-pills" aria-label="{{ __('Migration steps') }}">
            @foreach ([
                1 => __('Plan'),
                2 => __('Members'),
                3 => __('Loans'),
                4 => __('Payments'),
                5 => __('Run'),
            ] as $step => $label)
                <button type="button" wire:click="goToStep({{ $step }})" @class([
                    'ff-tenant-tab-pills__item ff-audit-wizard-steps__item',
                    'ff-tenant-tab-pills__item--active' => $currentStep === $step,
                ])>
                    <span class="ff-audit-wizard-steps__number">{{ $step }}</span>
                    <span class="ff-audit-wizard-steps__label">{{ $label }}</span>
                </button>
            @endforeach
        </nav>

        @if ($currentStep === 1)
            <section class="ff-maintenance-panel">
                <header class="ff-maintenance-panel__header ff-maintenance-panel__header--blue">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Recommended approach') }}</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            {{ __('You do not need to replay every old payment if you can snapshot balances at a cut-off date.') }}
                        </p>
                    </div>
                </header>
                <div class="ff-maintenance-panel__body space-y-4 text-sm text-gray-600 dark:text-gray-300">
                    <ol class="list-decimal space-y-3 ps-5">
                        <li>
                            <strong class="text-gray-900 dark:text-white">{{ __('Pick a cut-off date') }}</strong>
                            — {{ __('Usually month-end before go-live. Late fees and delinquency are not imported.') }}
                        </li>
                        <li>
                            <strong class="text-gray-900 dark:text-white">{{ __('Prepare members CSV') }}</strong>
                            — {{ __('One row per member with cutoff_cash_balance and cutoff_fund_balance as of the cut-off. These become opening ledger balances.') }}
                        </li>
                        <li>
                            <strong class="text-gray-900 dark:text-white">{{ __('Prepare loans CSV') }}</strong>
                            — {{ __('One row per active loan with amount_approved, disbursed_at, paid_installments_count, total_amount_repaid, and optional guarantor_member_number or guarantor_name. Identify the borrower with member_number or member_name.') }}
                        </li>
                        <li>
                            <strong class="text-gray-900 dark:text-white">{{ __('Optional: payments CSV') }}</strong>
                            — {{ __('Only if you need contribution/repayment history. Use the classifier when payment_type is unknown.') }}
                        </li>
                        <li>
                            <strong class="text-gray-900 dark:text-white">{{ __('Dry run, then import') }}</strong>
                            — {{ __('Preview row counts, fix CSV errors, run the migration, then verify on the Reconciliation page.') }}
                        </li>
                    </ol>

                    <div class="ff-maintenance-callout">
                        <p class="font-medium text-gray-800 dark:text-gray-200">{{ __('When you only have a mixed payment list') }}</p>
                        <p class="mt-1">
                            {{ __('Use snapshot mode: derive each member\'s cash and fund balances from your old system (or bank + internal reports) instead of classifying every payment. Import loans separately with outstanding totals.') }}
                        </p>
                    </div>
                </div>
            </section>
        @endif

        @if ($currentStep === 2)
            <section class="ff-maintenance-panel">
                <header class="ff-maintenance-panel__header ff-maintenance-panel__header--emerald">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Members file format') }}</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('UTF-8 CSV with header row. Import parent members before dependents.') }}</p>
                    </div>
                </header>
                <div class="ff-maintenance-panel__body space-y-4 text-sm">
                    <p>
                        <a href="{{ route('tenant.downloads.legacy-members-import-sample') }}" target="_blank" rel="noopener"
                            class="font-medium text-sky-600 hover:text-sky-700 hover:underline dark:text-sky-400 dark:hover:text-sky-300">
                            {{ __('Download members sample CSV') }}
                        </a>
                        <span class="text-gray-500 dark:text-gray-400">
                            — {{ __('Uses the same member numbers, names, and emails as the loans and payments samples below.') }}
                        </span>
                    </p>
                    @include('filament.tenant.partials.legacy-migration-column-table', [
                        'columns' => \App\Support\LegacyMigrationSampleCsv::memberColumnDocs(),
                    ])
                </div>
            </section>
        @endif

        @if ($currentStep === 3)
            <section class="ff-maintenance-panel">
                <header class="ff-maintenance-panel__header ff-maintenance-panel__header--emerald">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Loans file format') }}</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Import after members exist. Use loan_status=active for outstanding loans.') }}</p>
                    </div>
                </header>
                <div class="ff-maintenance-panel__body space-y-4 text-sm">
                    <p>
                        <a href="{{ route('tenant.downloads.legacy-loans-import-sample') }}" target="_blank" rel="noopener"
                            class="font-medium text-sky-600 hover:text-sky-700 hover:underline dark:text-sky-400 dark:hover:text-sky-300">
                            {{ __('Download loans sample CSV') }}
                        </a>
                        <span class="text-gray-500 dark:text-gray-400">
                            — {{ __('Borrower numbers and names match the members sample (import members first).') }}
                        </span>
                    </p>
                    @include('filament.tenant.partials.legacy-migration-column-table', [
                        'columns' => \App\Support\LegacyMigrationSampleCsv::loanColumnDocs(),
                    ])
                </div>
            </section>
        @endif

        @if ($currentStep === 4)
            <section class="ff-maintenance-panel">
                <header class="ff-maintenance-panel__header ff-maintenance-panel__header--emerald">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Payments file format (optional)') }}</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Only used with the historical strategy. Skip this step for snapshot migration.') }}</p>
                    </div>
                </header>
                <div class="ff-maintenance-panel__body space-y-4 text-sm">
                    <p>
                        <a href="{{ route('tenant.downloads.legacy-payments-import-sample') }}" target="_blank" rel="noopener"
                            class="font-medium text-sky-600 hover:text-sky-700 hover:underline dark:text-sky-400 dark:hover:text-sky-300">
                            {{ __('Download payments sample CSV') }}
                        </a>
                    </p>
                    @include('filament.tenant.partials.legacy-migration-column-table', [
                        'columns' => \App\Support\LegacyMigrationSampleCsv::paymentColumnDocs(),
                    ])
                </div>
            </section>
        @endif

        @if ($currentStep === 5)
            {{-- Upload form (step 5 only; kept in DOM for Livewire file state) --}}
            <section class="ff-maintenance-panel">
                <header class="ff-maintenance-panel__header ff-maintenance-panel__header--muted">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Upload files & settings') }}</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            {{ __('Choose strategy, cut-off date, default password, and CSV files. Wait until each upload finishes before preview or run.') }}
                        </p>
                    </div>
                </header>
                <div class="ff-maintenance-panel__body space-y-4">
                    {{ $this->form }}

                    @include('filament.tenant.partials.audit-system.workspace-actions', [
                        'names' => ['previewMigration', 'classifyPayments', 'dryRun', 'runMigration'],
                        'class' => 'border-t border-gray-100 pt-4 dark:border-white/10',
                    ])
                </div>
            </section>

            @if ($classificationStats)
                <section class="ff-maintenance-panel">
                    <header class="ff-maintenance-panel__header ff-maintenance-panel__header--muted">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Payment classification results') }}</h2>
                    </header>
                    <div class="ff-maintenance-panel__body">
                        @include('filament.tenant.partials.legacy-migration-classification-results')
                    </div>
                </section>
            @endif

            @if ($lastPreview)
                <section class="ff-maintenance-panel">
                    <header class="ff-maintenance-panel__header ff-maintenance-panel__header--muted">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Preview summary') }}</h2>
                    </header>
                    <div class="ff-maintenance-panel__body space-y-4 text-sm">
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
                </section>
            @endif

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
                        <pre class="overflow-x-auto rounded-lg bg-gray-50 p-4 text-xs dark:bg-gray-900/50">{{ json_encode($lastRun, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                    </div>
                </section>
            @endif
        @endif

        @if ($currentStep !== 5)
            {{-- Keep form state alive while browsing documentation steps --}}
            <div class="hidden" aria-hidden="true">
                {{ $this->form }}
            </div>
        @endif

        <footer class="ff-audit-wizard-footer flex items-center justify-between gap-3 border-t border-gray-100 pt-4 dark:border-white/10">
            @if ($currentStep > 1)
                <x-filament::button type="button" wire:click="previousStep" color="gray" outlined>
                    {{ __('Back') }}
                </x-filament::button>
            @else
                <span></span>
            @endif

            @if ($currentStep < 5)
                <x-filament::button type="button" wire:click="nextStep" color="primary">
                    {{ __('Next') }}
                </x-filament::button>
            @else
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Use the actions above to preview, classify, dry run, or run the migration.') }}
                </p>
            @endif
        </footer>
    </div>
