<x-filament-panels::page>
    <div class="ff-legacy-migration mx-auto w-full max-w-5xl space-y-8">
        {{-- Step navigation --}}
        <nav class="flex flex-wrap gap-2" aria-label="{{ __('Migration steps') }}">
            @foreach ([
                1 => __('Plan'),
                2 => __('Members'),
                3 => __('Loans'),
                4 => __('Payments'),
                5 => __('Run'),
            ] as $step => $label)
                <button type="button" wire:click="goToStep({{ $step }})" @class([
                    'rounded-lg px-3 py-1.5 text-sm font-medium transition',
                    'bg-primary-600 text-white shadow-sm dark:bg-primary-500' => $currentStep === $step,
                    'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10' => $currentStep !== $step,
                ])>
                    {{ $step }}. {{ $label }}
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
                            class="font-medium text-primary-600 hover:underline dark:text-primary-400">
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
                            class="font-medium text-primary-600 hover:underline dark:text-primary-400">
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
                            class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                            {{ __('Download payments sample CSV') }}
                        </a>
                    </p>
                    @include('filament.tenant.partials.legacy-migration-column-table', [
                        'columns' => \App\Support\LegacyMigrationSampleCsv::paymentColumnDocs(),
                    ])
                </div>
            </section>
        @endif

        {{-- Upload form (always mounted so file state persists across steps) --}}
        <section class="ff-maintenance-panel">
            <header class="ff-maintenance-panel__header ff-maintenance-panel__header--muted">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Upload files & settings') }}</h2>
            </header>
            <div class="ff-maintenance-panel__body">
                {{ $this->form }}

                <div class="mt-6 flex flex-wrap gap-3">
                    <x-filament::button wire:click="previewMigration" color="gray">
                        {{ __('Preview') }}
                    </x-filament::button>
                    <x-filament::button wire:click="classifyPayments" color="gray"
                        wire:loading.attr="disabled">
                        {{ __('Classify payments') }}
                    </x-filament::button>
                    <x-filament::button wire:click="runMigration(true)" color="warning">
                        {{ __('Dry run') }}
                    </x-filament::button>
                    <x-filament::button wire:click="runMigration(false)" color="primary"
                        wire:confirm="{{ __('Run the migration now? This writes members, loans, and optional payments to the database.') }}">
                        {{ __('Run migration') }}
                    </x-filament::button>
                </div>
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
    </div>
</x-filament-panels::page>
