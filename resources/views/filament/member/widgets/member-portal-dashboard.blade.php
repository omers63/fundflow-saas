@php
$d = $d ?? [];
$currency = $currency ?? null;
@endphp

@if (empty($d))
    <div class="rounded-xl border border-dashed border-gray-200 px-4 py-6 text-center text-sm text-gray-500">
        {{ __('Loading your dashboard…') }}
    </div>
@else
    <div class="ff-member-dashboard-overview w-full max-w-none space-y-3.5">
        @if (!empty($d['greeting']))
            @include('filament.member.widgets.partials.portal-greeting-hero', [
                'greeting' => $d['greeting'],
                'currency' => $currency,
            ])
        @endif

        @if (!empty($d['notice']))
            @php $notice = $d['notice']; @endphp
            <x-member::notice :tone="$notice['tone']" :title="$notice['title'] ?? null">
                <p class="m-0">{!! $notice['body'] ?? '' !!}</p>
                @if (!empty($notice['action']['url'] ?? null))
                    <p class="m-0 mt-1">
                        <a href="{{ $notice['action']['url'] }}" wire:navigate class="font-semibold underline">
                            {{ $notice['action']['label'] }} →
                        </a>
                    </p>
                @endif
            </x-member::notice>
        @endif

        @if (!empty($d['pending_actions']))
            <div class="space-y-2">
                @foreach ($d['pending_actions'] as $pending)
                    <x-member::notice :tone="$pending['tone'] ?? 'amber'">
                        <p class="m-0">
                            <a href="{{ $pending['url'] }}" wire:navigate class="font-semibold underline">
                                {{ $pending['label'] }} →
                            </a>
                        </p>
                    </x-member::notice>
                @endforeach
            </div>
        @endif

        <div class="ff-member-dashboard-account-grid grid grid-cols-1 gap-3.5 sm:grid-cols-2">
            @if (!empty($d['cash_card']))
                @php $cash = $d['cash_card']; @endphp
                <x-member::panel :title="__('Cash account')" :link="$cash['details_url'] ?? null" :link-label="__('Details')"
                    class="ff-member-cash-hero">
                    <div class="ff-member-dashboard-account-card">
                        <div class="ff-member-dashboard-account-card__main">
                            <div class="ff-member-dashboard-balance">
                                <x-member::amount :value="$cash['balance']" :currency="$currency"
                                    class="ff-member-dashboard-balance__value ff-member-dashboard-balance__value--cash" />
                                <p class="ff-member-dashboard-balance__label ff-member-dashboard-balance__label--cash">{{ $cash['balance_label'] ?? __('Available balance') }}
                                </p>
                            </div>
                            @if (filled($cash['reserved_emi'] ?? null))
                                <p class="ff-member-dashboard-meta">
                                    {{ __('Reserved (next EMI)') }}:
                                    <x-member::amount :value="$cash['reserved_emi']" :currency="$currency"
                                        class="ff-member-dashboard-meta__amount" />
                                </p>
                            @endif
                        </div>
                        <div class="ff-member-dashboard-actions">
                            @foreach ($cash['actions'] ?? [] as $action)
                                <a href="{{ $action['url'] }}" wire:navigate class="fi-btn fi-btn-size-sm fi-outlined fi-color-gray">
                                    {{ $action['label'] }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                </x-member::panel>
            @endif

            @if (!empty($d['fund_card']))
                @php $fund = $d['fund_card']; @endphp
                <x-member::panel :title="__('Fund account')" :link="$fund['details_url'] ?? null" :link-label="__('Details')"
                    class="ff-member-fund-hero">
                    <div class="ff-member-dashboard-account-card">
                        <div class="ff-member-dashboard-account-card__main">
                            <div class="ff-member-dashboard-balance">
                                <x-member::amount :value="$fund['balance']" :currency="$currency"
                                    class="ff-member-dashboard-balance__value ff-member-dashboard-balance__value--fund" />
                                <p class="ff-member-dashboard-balance__label ff-member-dashboard-balance__label--fund">
                                    {{ __('Accumulated') }} · {{ __('Loan cap') }}
                                    <x-member::amount :value="$fund['headroom']" :currency="$currency" class="inline" />
                                </p>
                            </div>
                        </div>
                        <div class="ff-member-dashboard-actions">
                            @foreach ($fund['actions'] ?? [] as $action)
                                <a href="{{ $action['url'] }}" wire:navigate class="fi-btn fi-btn-size-sm fi-outlined fi-color-gray">
                                    {{ $action['label'] }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                </x-member::panel>
            @endif
        </div>

        @php
    $hasLoanPanel = !empty($d['loan_panel']);
    $hasEligibilityPanel = !empty($d['eligibility_panel']);
    $hasQuickActions = !empty($d['quick_actions']);
    $hasLoanColumn = $hasLoanPanel || $hasEligibilityPanel;
        @endphp

        @if ($hasLoanColumn || $hasQuickActions)
            <div class="grid grid-cols-1 gap-3.5 lg:grid-cols-5 lg:items-start">
                @if ($hasLoanColumn)
                    <div @class(['lg:col-span-3' => $hasQuickActions, 'col-span-full' => !$hasQuickActions])>
                        @if ($hasLoanPanel)
                            @php $loan = $d['loan_panel']; @endphp
                            <x-member::panel :title="$loan['label'] ?? __('Active loan')" :link="$loan['view_url'] ?? null"
                                :link-label="__('Details')">
                                <div class="ff-member-loan-card__header-row mb-2">
                                    <div class="ff-member-loan-card__header-main">
                                        <x-member::amount :value="$loan['outstanding']" :currency="$currency" class="text-xl font-bold" />
                                        <x-member::chip :variant="$loan['status_variant'] ?? 'green'">{{ $loan['status_label'] ?? '' }}</x-member::chip>
                                    </div>
                                    <p class="ff-member-loan-card__meta-line ff-member-dashboard-meta">{{ $loan['installments_label'] ?? '' }}</p>
                                </div>
                                <x-member::progress-bar :percent="$loan['repay_percent'] ?? 0" class="mb-2" />
                                <p class="ff-member-dashboard-meta mb-3">
                                    @if (filled($loan['repaid_amount'] ?? null))
                                        <x-member::amount :value="$loan['repaid_amount']" :currency="$currency" class="inline" />
                                        {{ __('repaid (:percent%)', ['percent' => $loan['repay_percent'] ?? 0]) }}
                                    @else
                                        {{ $loan['repaid_label'] ?? '' }}
                                    @endif
                                </p>
                                @if (!empty($loan['next_emi']))
                                    <div class="ff-member-dashboard-emi-row mb-3">
                                        <div>
                                            <p class="ff-member-dashboard-meta m-0">{{ __('Next EMI due') }}</p>
                                            <p class="m-0 text-sm font-semibold">{{ $loan['next_emi']['due_date'] ?? '—' }}</p>
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
                                @if (filled($loan['settle_url'] ?? null))
                                    <x-member::panel-actions>
                                        <a href="{{ $loan['settle_url'] }}" wire:navigate class="fi-btn fi-btn-size-sm fi-outlined fi-color-gray">
                                            {{ __('Partial settle') }}
                                        </a>
                                        <a href="{{ $loan['settle_url'] }}" wire:navigate class="fi-btn fi-btn-size-sm fi-outlined fi-color-primary">
                                            {{ __('Full settle') }}
                                        </a>
                                    </x-member::panel-actions>
                                @endif
                            </x-member::panel>
                        @elseif ($hasEligibilityPanel)
                            @php $eligibility = $d['eligibility_panel']; @endphp
                            <x-member::panel :title="__('Loan eligibility')" class="ff-member-loan-eligibility-panel">
                                <div class="ff-member-dashboard-eligibility">
                                    @if ($eligibility['eligible'] ?? false)
                                        <div class="ff-member-dashboard-eligibility__copy space-y-2">
                                            <p class="m-0 text-sm font-semibold text-emerald-700 dark:text-emerald-400">
                                                {{ __('You are eligible to apply for a loan') }}
                                            </p>
                                            <p class="ff-member-dashboard-meta m-0">
                                                {{ __('Maximum amount') }}:
                                                <x-member::amount :value="$eligibility['max_amount']" :currency="$currency" />
                                            </p>
                                        </div>
                                        <div class="ff-member-dashboard-actions ff-member-dashboard-eligibility__actions">
                                            <a href="{{ $eligibility['apply_url'] }}" wire:navigate
                                                class="fi-btn fi-btn-size-sm fi-color-primary">
                                                {{ __('Apply for loan') }}
                                            </a>
                                        </div>
                                    @else
                                        <div class="ff-member-dashboard-eligibility__copy space-y-2">
                                            <p class="m-0 text-sm font-semibold">{{ __('Not eligible for a loan') }}</p>
                                            @if (filled($eligibility['reason'] ?? null))
                                                <p class="ff-member-dashboard-meta m-0">{{ $eligibility['reason'] }}</p>
                                            @endif
                                            @if ($eligibility['has_pending_override_request'] ?? false)
                                                <p class="ff-member-dashboard-meta m-0">
                                                    {{ __('An administrator is reviewing your loan eligibility request.') }}
                                                </p>
                                            @endif
                                            </div>
                                            @if (($eligibility['has_pending_override_request'] ?? false) || ($eligibility['can_request_override'] ?? false))
                                                <div class="ff-member-dashboard-actions ff-member-dashboard-eligibility__actions">
                                                    @if ($eligibility['has_pending_override_request'] ?? false)
                                                        <x-member::chip variant="amber">{{ __('Review pending') }}</x-member::chip>
                                                        <a href="{{ $eligibility['loans_url'] }}" wire:navigate class="fi-btn fi-btn-size-sm fi-outlined fi-color-primary">
                                                            {{ __('My loans') }}
                                                        </a>
                                                    @elseif ($eligibility['can_request_override'] ?? false)
                                                        <a href="{{ $eligibility['request_url'] }}" wire:navigate class="fi-btn fi-btn-size-sm fi-color-warning">
                                                            {{ __('Request eligibility review') }}
                                                        </a>
                                                    @endif
                                                </div>
                                            @endif
                                    @endif
                                </div>
                            </x-member::panel>
                        @endif
                    </div>
                @endif

                @if ($hasQuickActions)
                    <div @class(['lg:col-span-2' => $hasLoanColumn, 'col-span-full' => !$hasLoanColumn])>
                        <x-member::panel :title="__('Quick actions')">
                            <div class="ff-member-dashboard-quick-actions space-y-1">
                                @php
            $quickIcons = [
                'deposit' => '⬇',
                'loan' => '📄',
                'statements' => '📥',
                'messages' => '💬',
                'accounts' => '👜',
                'fund' => '🏦',
                'guaranteed' => '🛡',
            ];
                                @endphp
                                @foreach ($d['quick_actions'] as $action)
                                    @if ($action['visible'] ?? false)
                                        <x-member::quick-action
                                            :href="$action['url']"
                                            :icon="$quickIcons[$action['tone'] ?? ''] ?? '•'"
                                            :title="$action['label']"
                                            :subtitle="$action['subtitle'] ?? $action['description'] ?? null"
                                        />
                                    @endif
                                @endforeach
                            </div>
                        </x-member::panel>
                    </div>
                @endif
            </div>
        @endif

        @if (!empty($d['recent_activity']))
            <x-member::panel :title="__('Recent transactions')" :link="$d['activity_url'] ?? null"
                :link-label="__('All')">
                <div class="overflow-x-auto">
                    <table class="ff-member-dashboard-table w-full">
                        <thead>
                            <tr>
                                <th>{{ __('Description') }}</th>
                                <th>{{ __('Date') }}</th>
                                <th>{{ __('Credit') }}</th>
                                <th>{{ __('Debit') }}</th>
                                <th>{{ __('Type') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($d['recent_activity'] as $row)
                                <tr>
                                    <td>{{ $row['description'] }}</td>
                                    <td>{{ $row['date'] }}</td>
                                    <td>
                                        @if (filled($row['credit'] ?? null))
                                            <x-member::amount :value="$row['credit']" :currency="$currency" signed
                                                class="ff-member-amount--success" />
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>
                                        @if (filled($row['debit'] ?? null))
                                            <x-member::amount :value="$row['debit']" :currency="$currency" signed
                                                class="ff-member-amount--danger" />
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>
                                        <x-member::chip :variant="($row['type'] ?? '') === 'CR' ? 'green' : 'gray'">
                                            {{ $row['type'] ?? '—' }}
                                        </x-member::chip>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-member::panel>
        @endif

        @if (!empty($d['expandable']['insights']) || !empty($d['expandable']['household']['dependents_count']) || !empty($d['expandable']['guarantor']))
            <details class="ff-member-dashboard-expandable">
                <summary>{{ __('More details') }}</summary>
                <div class="mt-3 space-y-3">
                    @if (!empty($d['expandable']['insights']['stat_groups']))
                        <x-member::panel :title="__('My insights')">
                            <div class="ff-member-dashboard-insights space-y-3">
                                @foreach ($d['expandable']['insights']['stat_groups'] as $group)
                                    <div class="ff-member-dashboard-insights-group">
                                        @if (filled($group['label'] ?? null))
                                            <p class="ff-member-dashboard-insights-group__label">{{ $group['label'] }}</p>
                                        @endif
                                        <div
                                            class="ff-member-dashboard-insights-stats grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                                            @foreach ($group['stats'] as $stat)
                                                <x-member::stat-card :label="$stat['label']" :value="$stat['value'] ?? null"
                                                    :amount="$stat['amount'] ?? null" :currency="$currency" />
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </x-member::panel>
                    @endif

                    @if (($d['expandable']['household']['dependents_count'] ?? 0) > 0 || filled($d['expandable']['household']['parent_name'] ?? null))
                        <x-member::panel :title="__('Household')">
                            @if (filled($d['expandable']['household']['parent_name'] ?? null))
                                <p class="ff-member-dashboard-meta">{{ __('Parent') }}:
                                    {{ $d['expandable']['household']['parent_name'] }}</p>
                            @endif
                            @if (!empty($d['expandable']['household']['dependents']))
                                <ul class="mt-2 space-y-2 text-sm">
                                    @foreach ($d['expandable']['household']['dependents'] as $dependent)
                                        <li class="flex flex-wrap items-center justify-between gap-2">
                                            <span>{{ $dependent['name'] }} · {{ $dependent['number'] }}</span>
                                            @if (filled($dependent['switch_url'] ?? null))
                                                <a href="{{ $dependent['switch_url'] }}"
                                                    class="text-xs font-semibold text-sky-600 hover:underline">
                                                    {{ __('Switch profile') }}
                                                </a>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            @if (filled($d['expandable']['household']['settings_url'] ?? null))
                                <p class="mt-3 mb-0">
                                    <a href="{{ $d['expandable']['household']['settings_url'] }}" wire:navigate
                                        class="text-xs font-semibold text-primary-600 hover:underline">
                                        {{ __('Manage household in settings') }} →
                                    </a>
                                </p>
                            @endif
                        </x-member::panel>
                    @endif

                    @if (!empty($d['expandable']['guarantor']))
                        @php $guarantor = $d['expandable']['guarantor']; @endphp
                        <x-member::panel :title="__('Guaranteed loans')">
                            <p class="ff-member-dashboard-meta mb-3">
                                {{ trans_choice('You guarantee :count active loan|You guarantee :count active loans', $guarantor['count'] ?? 0, ['count' => $guarantor['count'] ?? 0]) }}
                            </p>
                            <a href="{{ $guarantor['url'] ?? '#' }}" wire:navigate class="fi-btn fi-btn-size-sm fi-outlined fi-color-primary">
                                {{ __('View guaranteed loans') }}
                            </a>
                        </x-member::panel>
                    @endif
                </div>
            </details>
        @endif
    </div>
@endif
